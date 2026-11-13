<?php

namespace App\Services;

use App\Models\AmortizationPrincipalChange;
use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\LatePenaltySetting;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\LoanLedgerEntry;
use App\Models\Repayment;
use App\Models\RepaymentAllocation;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use App\Services\Accounting\AutomaticPoster;
use App\Services\Penalties\BracketRate;
use App\Services\Penalties\PenaltyTerms;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RepaymentService
{
    /** The preview's payment badge labels, by type (see paymentBadge()). */
    private const PAYMENT_BADGES = [
        'partial' => 'Partial Payment',
        'exact' => 'Exact Payment',
        'advance' => 'Advance Payment',
    ];

    /**
     * Record a repayment and allocate it across schedules.
     *
     * The check before the transaction refuses, with a 422, a payment that was
     * wrong from the start. The transaction then locks the loan row as its
     * FIRST statement (lockLoanForPayment()), the order every loan write takes
     * (LoanWriteTransaction), and records the payment under that lock
     * (recordRepaymentOnLockedLoan()).
     *
     * The lock is what keeps a void of an earlier payment from being lost.
     * Under REPEATABLE READ the transaction's snapshot is fixed by its first
     * plain read, and the allocation writes each period it pays from what it
     * read. Without the lock, a void that committed after this payment's first
     * read of the schedule had its reversal written back over by this
     * payment's figures. Holding the loan row, a void (which locks the same
     * row) waits until this payment commits, and every read here comes after
     * the lock, so it starts from what the last write left.
     *
     * A loan closed between the request reading it and the lock is a 409, as
     * is a deadlock or a lock wait timeout, with nothing of the payment left.
     * This is the outermost transaction: RepaymentController::store(),
     * DemoSeeder and the test helpers are the callers; AutoPayService goes
     * through processRepaymentWithAmountUnderLock(). A caller that already
     * holds the loan's lock in its own transaction calls recordRepaymentOnLockedLoan() instead.
     */
    public function processRepayment(
        Loan $loan,
        float $amountPaid,
        string $paymentDate,
        User $user,
        ?string $remarks = null,
        string $method = 'cash',
        ?string $referenceNumber = null,
    ): Repayment {
        if (! in_array($loan->status, Loan::COLLECTIBLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'loan' => 'Payments can only be recorded for released, ongoing or defaulted loans.',
            ]);
        }

        return LoanWriteTransaction::run(function () use ($loan, $amountPaid, $paymentDate, $user, $remarks, $method, $referenceNumber): Repayment {
            $this->lockLoanForPayment($loan, Loan::COLLECTIBLE_STATUSES);

            return $this->recordRepaymentOnLockedLoan($loan, $amountPaid, $paymentDate, $user, $remarks, $method, $referenceNumber);
        }, racedUniqueIndex: Repayment::RECEIPT_NUMBER_INDEX);
    }

    /**
     * Record a repayment whose amount is worked out under the loan's lock, as
     * processRepayment() records one: same transaction, same lock order (the
     * loan row first, no collateral), same 409s.
     *
     * `$amountUnderLock` gets the locked loan and returns what to pay, read
     * from what is committed once the lock is held, so a payment that landed
     * after the caller first read the periods is seen. Released and ongoing
     * loans only: auto-pay never charges a defaulted loan. Late penalties are
     * applied first (applyPenalties()), so the amount covers the penalty the
     * payment charges. When it returns 0 or less nothing is recorded and null
     * is returned. For AutoPayService.
     *
     * @param  Closure(Loan): float  $amountUnderLock
     */
    public function processRepaymentWithAmountUnderLock(
        Loan $loan,
        Closure $amountUnderLock,
        string $paymentDate,
        User $user,
        ?string $remarks = null,
        string $method = 'cash',
        ?string $referenceNumber = null,
    ): ?Repayment {
        if (! in_array($loan->status, Loan::ACTIVE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'loan' => 'Repayments can only be recorded for released or ongoing loans.',
            ]);
        }

        return LoanWriteTransaction::run(function () use ($loan, $amountUnderLock, $paymentDate, $user, $remarks, $method, $referenceNumber): ?Repayment {
            $this->lockLoanForPayment($loan, Loan::ACTIVE_STATUSES);

            // The payment's own first step, run before the amount is worked out
            // so it includes the late penalty the payment will charge (waived
            // periods excluded, never below what was paid). Running it again in
            // recordRepaymentOnLockedLoan() writes the same figures.
            $this->applyPenalties($loan, Carbon::parse($paymentDate));

            $amount = round((float) $amountUnderLock($loan), 2);

            if ($amount <= 0) {
                return null;
            }

            return $this->recordRepaymentOnLockedLoan($loan, $amount, $paymentDate, $user, $remarks, $method, $referenceNumber);
        }, racedUniqueIndex: Repayment::RECEIPT_NUMBER_INDEX);
    }

    /**
     * Lock the loan a payment is recorded on and refuse with a 409 unless its
     * status is still one of `$statuses`: Loan::COLLECTIBLE_STATUSES for a
     * payment staff record (a defaulted loan takes recovery payments),
     * Loan::ACTIVE_STATUSES for auto-pay.
     *
     * A locking read, and the first statement of the transaction, so it fixes
     * no snapshot: every plain read after it sees what is committed once the
     * lock is held. No collateral rows: a payment moves a loan from `released`
     * to `ongoing` or out to `completed` (a defaulted loan stays defaulted
     * until it is paid in full), never INTO Loan::PLEDGING_STATUSES
     * from outside it, so it has no pledge to guard. `$loan` is refreshed from
     * the locked row.
     *
     * @param  list<string>  $statuses
     *
     * @throws HttpResponseException 409 when the loan was closed since it was read
     */
    private function lockLoanForPayment(Loan $loan, array $statuses): void
    {
        $locked = Loan::whereKey($loan->getKey())->lockForUpdate()->first();

        if ($locked === null || ! in_array($locked->status, $statuses, true)) {
            throw LoanWriteTransaction::conflict();
        }

        $loan->setRawAttributes($locked->getAttributes(), true);
    }

    /**
     * Record a repayment on a loan whose row the caller has already locked,
     * inside the caller's own transaction, and allocate it across schedules.
     *
     * For a caller that holds the loan's lock in an outermost transaction of
     * its own and must not open another: LoanAdjustmentService::extendLoan(),
     * which collects the interest under its extension's lock, and the
     * previews that run in rolledBackUnderPaymentLock(): previewAllocation()
     * and LoanAdjustmentService::extensionPreview(). Everyone else calls
     * processRepayment().
     */
    public function recordRepaymentOnLockedLoan(
        Loan $loan,
        float $amountPaid,
        string $paymentDate,
        User $user,
        ?string $remarks = null,
        string $method = 'cash',
        ?string $referenceNumber = null,
        bool $postToBooks = true,
        bool $preview = false,
    ): Repayment {
        $paymentDate = Carbon::parse($paymentDate);

        // The loan's status as locked, kept on the payment so a void puts back
        // exactly that (voidRepayment()).
        $loanStatusBefore = $loan->status;

        // On a defaulted loan, the penalty each period was charged before the
        // default: the most this payment, and the re-charge after it, may
        // leave owed (penaltyStateAsOf()).
        $penaltyCeilings = self::penaltyIsFrozen($loan) ? $this->penaltyCeilings($loan) : null;

        // Step 1: Compute penalties on overdue schedules
        $this->applyPenalties($loan, $paymentDate, ceilings: $penaltyCeilings);

        // Capture outstanding principal balance before payment
        $balanceBefore = (float) $loan->amortizationSchedules()
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->sum(DB::raw('principal_due - principal_paid'));

        // Step 2: Get all unpaid/partial schedules ordered by period
        $schedules = $loan->amortizationSchedules()
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->orderBy('period_number')
            ->get();

        // What the payment is measured against, as the preview's badge measures
        // it: read before the loop below allocates to these periods.
        $owedIds = self::owedAmong($schedules, $paymentDate);

        // Identify "current" schedule = first not-overdue schedule (due_date >= paymentDate)
        // If all unpaid are overdue, the current = the most recent overdue (largest period_number among overdue)
        $currentScheduleId = null;
        $firstNotOverdue = $schedules->first(fn ($s) => $s->due_date->gte($paymentDate));
        if ($firstNotOverdue) {
            $currentScheduleId = $firstNotOverdue->id;
        } else {
            $currentScheduleId = $schedules->last()?->id;
        }

        // The insurance premium the release left owed on the loan comes before
        // anything else, penalty included: a separate, interest-free amount,
        // never principal. Worked out in centavos so what the periods get is
        // exact. The loan row is locked by the caller, so this reads what the
        // last write left.
        $insuranceOwed = LoanService::toCentavos((float) $loan->insurance_remaining_balance);
        $insuranceCentavos = max(0, min($insuranceOwed, LoanService::toCentavos($amountPaid)));
        $insuranceApplied = $insuranceCentavos / 100;

        // Everything below is whole centavos: each step takes the smaller of
        // what a part owes and what is left, so a payment of exactly what the
        // periods owe leaves exactly nothing, never float dust that reaches
        // the next period.
        $remaining = LoanService::toCentavos($amountPaid) - $insuranceCentavos;
        $principalApplied = 0;
        $interestApplied = 0;
        $penaltyApplied = 0;
        $overdueInterestApplied = 0;
        $currentInterestApplied = 0;
        $currentPrincipalApplied = 0;
        $nextInterestApplied = 0;
        $nextPrincipalApplied = 0;
        $touchedFutureSchedule = false;
        $allocations = [];

        // Business rule (frontend PR #106): on SCB-bearing loans, overpayment must NOT pre-pay
        // future amortization — it becomes share capital instead. The frontend computes the SCB
        // credit and posts to /share-capital/ledger separately; here we just stop cascading so
        // the remainder surfaces as `overpayment` on the repayment row.
        $hasScb = (float) $loan->scb_amount > 0;
        $currentHandled = false;

        foreach ($schedules as $schedule) {
            if ($remaining <= 0) {
                break;
            }

            $isCurrent = $schedule->id === $currentScheduleId;
            $isOverdue = $schedule->due_date->lt($paymentDate);
            $isFuture = $schedule->due_date->gt($paymentDate);

            // With SCB: once the current schedule is fully allocated, stop the cascade.
            // Overdue schedules earlier in the period list still get caught up (rule #2 in allocation order).
            if ($hasScb && $currentHandled && ! $isOverdue) {
                break;
            }

            // Allocate: penalty → interest → principal
            [$remaining, $pPaid, $iPaid, $penPaid] = $this->allocateToSchedule(
                $schedule,
                $remaining,
            );

            $principalApplied += $pPaid;
            $interestApplied += $iPaid;
            $penaltyApplied += $penPaid;

            // Only a period that actually took money gets an allocation row,
            // and only money reaching a future period makes the payment an
            // advance.
            if ($pPaid > 0 || $iPaid > 0 || $penPaid > 0) {
                $allocations[] = [
                    'amortization_schedule_id' => $schedule->id,
                    'period_number' => $schedule->period_number,
                    'penalty' => $penPaid / 100,
                    'interest' => $iPaid / 100,
                    'principal' => $pPaid / 100,
                ];

                if ($isFuture && ! $hasScb) {
                    $touchedFutureSchedule = true;
                }
            }

            // Categorize into 6-tier breakdown for frontend display:
            // - Schedules with due_date < paymentDate → "overdue" buckets (interest + principal both go here)
            // - The "current" schedule (first not-overdue, or most recent overdue if all are overdue) → "current" buckets
            // - Schedules after the current → "next" buckets
            if ($isCurrent) {
                $currentInterestApplied += $iPaid;
                $currentPrincipalApplied += $pPaid;
                $currentHandled = true;
            } elseif ($isOverdue) {
                // Overdue but not the "current" schedule → bucket as overdue interest
                // (overdue principal collapses into the current period principal display in the UI;
                // we don't have a dedicated overdue_principal column)
                $overdueInterestApplied += $iPaid;
                $currentPrincipalApplied += $pPaid;
            } else {
                // Future schedule (excess flows here when scb_amount == 0)
                $nextInterestApplied += $iPaid;
                $nextPrincipalApplied += $pPaid;
            }
        }

        // Step 3: Remainder becomes overpayment
        $overpayment = max(0, $remaining);

        // Step 4: Determine payment type
        $allDueSchedules = $loan->amortizationSchedules()
            ->where('due_date', '<=', $paymentDate)
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->count();

        if ($hasScb) {
            $paymentType = $this->buildUpLoanPaymentType(
                $loan,
                $schedules,
                $owedIds,
                array_column($allocations, 'amortization_schedule_id'),
                $overpayment,
                $insuranceOwed - $insuranceCentavos > 0,
            );
        } elseif ($touchedFutureSchedule) {
            $paymentType = 'advance';
        } elseif ($allDueSchedules === 0 && $overpayment === 0) {
            $paymentType = 'exact';
        } else {
            $paymentType = 'partial';
        }

        // Step 5: Persist repayment
        $balanceBeforeCentavos = LoanService::toCentavos($balanceBefore);
        $balanceAfterCentavos = max(0, $balanceBeforeCentavos - $principalApplied);

        $repayment = Repayment::create([
            'loan_id' => $loan->id,
            'payment_date' => $paymentDate,
            'method' => $method,
            'reference_number' => $referenceNumber,
            'amount_paid' => $amountPaid,
            'principal_applied' => $principalApplied / 100,
            'interest_applied' => $interestApplied / 100,
            'penalty_applied' => $penaltyApplied / 100,
            'insurance_applied' => $insuranceApplied,
            'overdue_interest_applied' => $overdueInterestApplied / 100,
            'current_interest_applied' => $currentInterestApplied / 100,
            'current_principal_applied' => $currentPrincipalApplied / 100,
            'next_interest_applied' => $nextInterestApplied / 100,
            'next_principal_applied' => $nextPrincipalApplied / 100,
            'overpayment' => $overpayment / 100,
            'balance_before' => $balanceBeforeCentavos / 100,
            'balance_after' => $balanceAfterCentavos / 100,
            'payment_type' => $paymentType,
            'status' => 'posted',
            'loan_status_before' => $loanStatusBefore,
            'received_by' => $user->id,
            'remarks' => $remarks,
            // A preview numbers its rolled-back row with a placeholder and
            // takes no lock on the newest receipt (Repayment::booted()).
            'receipt_number' => $preview ? Repayment::previewReceiptNumber() : null,
        ]);

        // What went on each period, so a void can take back exactly that.
        $repayment->allocations()->createMany($allocations);

        // And what it took of the premium owed, which the void puts back from
        // `insurance_applied`.
        if ($insuranceCentavos > 0) {
            $loan->update(['insurance_remaining_balance' => ($insuranceOwed - $insuranceCentavos) / 100]);
        }

        // Step 6: Loan status lifecycle
        // released → ongoing on first payment, → completed when all schedules paid.
        // A defaulted loan stays defaulted until it is paid in full: a partial
        // recovery doesn't cure the default.
        $unpaidCount = $loan->amortizationSchedules()
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->count();

        // A loan that still owes insurance premium stays open until the
        // premium is paid, even with every period settled.
        if ($unpaidCount === 0 && ! $loan->owesInsurancePremium()) {
            $loan->update(['status' => 'completed']);
        } elseif ($loan->status === 'released') {
            // No CollateralPledgeGuard call here on purpose. `released` and
            // `ongoing` are BOTH in Loan::PLEDGING_STATUSES, so this loan is
            // already an active holder of whatever it holds and this write
            // cannot add one. The guard is for transitions INTO the active
            // set from outside it; see voidRepayment() below.
            $loan->update(['status' => 'ongoing']);
        }

        // A payment dated before today took each period's penalty as of its
        // own date (step 1). The periods it leaves open owe the months since
        // too, so they are charged again as of today, as the nightly run
        // would, each status left as the allocation set it.
        $today = Carbon::today();

        if ($paymentDate->lt($today)) {
            $this->applyPenalties($loan, $today, markOverdue: false, ceilings: $penaltyCeilings);
        }

        // Share capital build-up: on an SCB-bearing loan, whatever the
        // allocation loop above could not apply to the current schedule
        // becomes a share capital credit instead of sitting as unallocated
        // overpayment. Posted in THIS transaction — see the docblock above
        // for why the whole payment has to be one all-or-nothing unit.
        //
        // `$hasScb` gates this, not bare `$overpayment > 0`: a full payoff
        // plus excess cash on a loan with no SCB is ordinary overpayment,
        // not share capital, and must not be credited here.
        //
        // NON_MEMBER_STATUSES mirrors the same gate
        // StoreShareCapitalLedgerRequest::memberBorrowerRule() enforces on
        // the standalone endpoint this replaces — a `pending` or `rejected`
        // borrower is not a member yet and cannot hold share capital.
        //
        // Gated on whole centavos, so a credited ₱0.00 row, which
        // reverseShareCapitalCredit()'s `credit > 0` lookup would never find
        // to clean up on a later void, is never written.
        $scbCredit = $overpayment / 100;

        if ($overpayment > 0 && self::creditsBuildUp($loan)) {
            ShareCapitalLedger::create([
                'borrower_id' => $loan->borrower_id,
                'repayment_id' => $repayment->id,
                // Its own reference, not one ShareCapitalLedger::booted()
                // numbers, as ShareCapitalReleaseCredit's is: that hook reads
                // the day's last number from this payment's snapshot, so two
                // payments could build the same one. A receipt number is
                // unique and at most 20 characters, in a 30-character column.
                'reference' => "SC-{$repayment->receipt_number}",
                'date' => $paymentDate,
                'description' => "Share capital build-up from payment {$repayment->receipt_number} (Loan {$loan->loan_account_number})",
                'debit' => 0,
                'credit' => $scbCredit,
                'created_by' => $user->id,
            ]);
        }

        /*
         * THE BOOKS. Explicit, and the LAST thing in this transaction.
         *
         * Last because the entry is built from the persisted repayment row —
         * `amount_paid` against `principal_applied` / `interest_applied` /
         * `penalty_applied` / `overpayment` — and those are only final once
         * the allocation loop above has run and the row has been written.
         *
         * ## This is exactly why it is not an observer
         *
         * previewAllocation() calls this method inside a transaction it then
         * ROLLS BACK, so the preview matches the real allocation exactly. A
         * `Repayment::created` observer would fire during that preview and
         * post a journal for a payment nobody made. Being inside the same
         * transaction is what makes the preview leave no trace: the rollback
         * takes the journal and its lines with it, by the same mechanism
         * that takes the repayment row.
         *
         * A throw rolls the payment back rather than recording a collection
         * the books do not mention.
         */
        // The loan is already in memory, so hand it over rather than let
        // the poster lazy-load it. AutoPayService::run() calls this method
        // in a loop over every auto-pay loan, and an avoidable SELECT per
        // repayment multiplies by the size of the batch.
        $repayment->setRelation('loan', $loan);

        $poster = app(AutomaticPoster::class);

        // previewAllocation() passes false. It still runs every rule — so a
        // preview refuses exactly where a real payment would — but writes
        // no journal, because JournalPoster::allocateJournalNo() takes a
        // row lock on the ledger's numbering hot row and a preview is a
        // read-only affordance called far more often than a payment.
        $postToBooks
            ? $poster->loanCollection($repayment, $user->id)
            : $poster->assertCollectionIsPostable($repayment);

        return $repayment;
    }

    /**
     * Compute how a hypothetical repayment would be allocated without persisting anything.
     *
     * Uses a real-but-rolled-back database transaction so the preview matches the actual
     * allocation exactly (penalty computation, schedule allocation, status flip): it runs
     * recordRepaymentOnLockedLoan(), the method processRepayment() records with, and then
     * rolls back everything, including the Repayment row itself. Not processRepayment(),
     * whose LoanWriteTransaction must be the outermost transaction.
     *
     * It locks the loan row first, as a payment does. The allocation it runs writes the
     * loan's periods (rolled back afterwards, but locked while it runs), so taking the
     * loan row in the same order as a payment keeps a preview and a payment on the same
     * loan from waiting on each other's rows the other way round.
     */
    public function previewAllocation(
        Loan $loan,
        float $amountPaid,
        string $paymentDate,
        User $user,
    ): array {
        if (! in_array($loan->status, Loan::COLLECTIBLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'loan' => 'Payment previews are only available for released, ongoing or defaulted loans.',
            ]);
        }

        return $this->rolledBackUnderPaymentLock($loan, function (Loan $loan) use ($amountPaid, $paymentDate, $user): array {
            $owedIds = $this->owedScheduleIds($loan, Carbon::parse($paymentDate));
            $premiumBefore = LoanService::toCentavos((float) $loan->insurance_remaining_balance);
            $repayment = $this->recordRepaymentOnLockedLoan($loan, $amountPaid, $paymentDate, $user, postToBooks: false, preview: true);
            $premiumAfter = LoanService::toCentavos((float) $loan->insurance_remaining_balance);
            $allocations = $this->previewedAllocations($repayment);
            $overpaymentCentavos = LoanService::toCentavos((float) $repayment->overpayment);
            // The share capital credit the payment itself wrote (creditsBuildUp()),
            // rolled back with it: the same code path that records the payment.
            $buildUpCentavos = (int) ShareCapitalLedger::where('repayment_id', $repayment->id)->where('credit', '>', 0)->get()
                ->sum(fn (ShareCapitalLedger $row): int => LoanService::toCentavos((float) $row->credit));
            $buildUpOnly = $this->overpaymentIsTheBuildUp(
                $loan,
                $overpaymentCentavos,
                AmortizationSchedule::whereIn('id', $owedIds)->get(),
            );
            $owedAfter = $loan->owedCentavos($loan->amortizationSchedules()->get())['total'];
            $applied = LoanService::toCentavos((float) $repayment->amount_paid) - LoanService::toCentavos((float) $repayment->overpayment);

            return [
                'amount_paid' => (float) $repayment->amount_paid,
                // Frontend-canonical scalar totals (consumed by /payments entry page)
                'total_paid' => (float) $repayment->amount_paid,
                'total_principal' => (float) $repayment->principal_applied,
                'total_interest' => (float) $repayment->interest_applied,
                'total_penalty' => (float) $repayment->penalty_applied,
                'excess' => (float) $repayment->overpayment,
                // How `excess` will be credited, as the recorded repayment reads it.
                'build_up_credited' => number_format($buildUpCentavos / 100, 2, '.', ''),
                'overpayment_kept' => number_format(($overpaymentCentavos - $buildUpCentavos) / 100, 2, '.', ''),
                // The insurance premium owed on the loan, collected first.
                'allocated_to_insurance' => (float) $repayment->insurance_applied,
                'total_insurance' => (float) $repayment->insurance_applied,
                'allocated_to_penalty' => (float) $repayment->penalty_applied,
                'allocated_to_overdue_interest' => (float) $repayment->overdue_interest_applied,
                'allocated_to_current_interest' => (float) $repayment->current_interest_applied,
                'allocated_to_current_principal' => (float) $repayment->current_principal_applied,
                'allocated_to_next_interest' => (float) $repayment->next_interest_applied,
                'allocated_to_next_principal' => (float) $repayment->next_principal_applied,
                // Total interest + principal applied across all schedules (legacy names)
                'total_interest_applied' => (float) $repayment->interest_applied,
                'total_principal_applied' => (float) $repayment->principal_applied,
                'overpayment' => (float) $repayment->overpayment,
                // The loan's outstanding balance (Loan::$outstanding_balance:
                // principal plus the insurance premium still owed) before and
                // after the payment. The stored repayment row keeps principal
                // only.
                'balance_before' => (LoanService::toCentavos((float) $repayment->balance_before) + $premiumBefore) / 100,
                'balance_after' => (LoanService::toCentavos((float) $repayment->balance_after) + $premiumAfter) / 100,
                // Everything the loan owes (the summary's `total_outstanding`:
                // principal, interest, penalty and premium) before and after
                // the payment. "Before" includes any penalty the payment
                // charges as of its date, so before less what it applies is
                // after; an overpayment or share capital credit lowers neither.
                'total_outstanding_before' => ($owedAfter + $applied) / 100,
                'total_outstanding_after' => $owedAfter / 100,
                'payment_type' => $repayment->payment_type,
                'payment_badge' => $this->paymentBadge($owedIds, $allocations, $buildUpOnly ? 0 : $overpaymentCentavos, $loan->owesInsurancePremium()),
                'allocations' => $allocations,
                'is_preview' => true,
            ];
        }, Loan::COLLECTIBLE_STATUSES);
    }

    /**
     * What a payment must be on `$asOf` to clear the insurance premium still
     * owed and every open period due on or before `$asOf` in full (the first
     * open period instead when none is due yet), in the order the allocation
     * takes money (recordRepaymentOnLockedLoan()): the premium, then period by
     * period its penalty as the payment's penalty step will charge it
     * (penaltyAsOf(), nothing written), interest and principal. Then the share
     * capital build-up of those periods, which a payment credits only on an
     * SCB-bearing loan of a member once the current period is settled, so only
     * when the current period is among them; otherwise the money would flow
     * into the current period instead.
     *
     * `period_number` and `schedule_id` are the first of those periods,
     * `last_period_number` the last; all three are null when no period is
     * open. Reads only.
     *
     * @param  EloquentCollection<int, AmortizationSchedule>  $schedules  the loan's periods
     * @return array{amount: float, insurance: float, principal: float, interest: float, penalty: float, share_capital_build_up: float, period_number: int|null, last_period_number: int|null, schedule_id: int|null}
     */
    public function amountDueNow(Loan $loan, EloquentCollection $schedules, Carbon $asOf): array
    {
        $paymentDate = $asOf->copy()->startOfDay();
        $centavos = fn (mixed $pesos): int => LoanService::toCentavos((float) ($pesos ?? 0));

        $open = $schedules
            ->filter(fn (AmortizationSchedule $s) => in_array($s->status, ['pending', 'partial', 'overdue'], true))
            ->sortBy('period_number')
            ->values();
        $dueNow = $open->filter(fn (AmortizationSchedule $s) => $s->due_date->lte($paymentDate))->values();
        $periods = $dueNow->isNotEmpty() ? $dueNow : $open->take(1);

        $insurance = max(0, $centavos($loan->insurance_remaining_balance));
        $principal = $interest = $penalty = $buildUp = 0;

        foreach ($periods as $period) {
            $principal += max(0, $centavos($period->principal_due) - $centavos($period->principal_paid));
            $interest += max(0, $centavos($period->interest_due) - $centavos($period->interest_paid));
            $charged = $this->penaltyAsOf($loan, $period, $paymentDate) ?? (float) $period->penalty_amount;
            $penalty += max(0, $centavos($charged) - $centavos($period->penalty_paid));
        }

        // The same "current" period the allocation picks.
        $current = $open->first(fn (AmortizationSchedule $s) => $s->due_date->gte($paymentDate)) ?? $open->last();

        if ($current !== null
            && (float) $loan->scb_amount > 0
            && $periods->contains('id', $current->id)
            && ! in_array($loan->borrower?->status, Borrower::NON_MEMBER_STATUSES, true)) {
            foreach ($periods as $period) {
                $buildUp += PersistedScheduleFigures::shareCapitalBuildUpCentavos($loan, $period);
            }
        }

        $first = $periods->first();
        $last = $periods->last();

        return [
            'amount' => round(($insurance + $penalty + $interest + $principal + $buildUp) / 100, 2),
            'insurance' => round($insurance / 100, 2),
            'principal' => round($principal / 100, 2),
            'interest' => round($interest / 100, 2),
            'penalty' => round($penalty / 100, 2),
            'share_capital_build_up' => round($buildUp / 100, 2),
            'period_number' => $first === null ? null : (int) $first->period_number,
            'last_period_number' => $last === null ? null : (int) $last->period_number,
            'schedule_id' => $first?->id,
        ];
    }

    /**
     * Run `$read` on the loan locked as a payment locks it, inside a transaction
     * that is rolled back whatever happens, so a preview can record and allocate
     * a payment with recordRepaymentOnLockedLoan() and read the result without
     * leaving a trace or a lock behind. A deadlock or lock wait timeout on the
     * loan lock answers 409, as recording the payment does.
     *
     * For previewAllocation() and LoanAdjustmentService::extensionPreview(),
     * which reads what the extension would collect under this lock, as
     * extendLoan() does under its own.
     *
     * `$statuses` are the loan statuses the lock accepts (a 409 otherwise):
     * released and ongoing by default, as an extension needs; the payment
     * preview passes Loan::COLLECTIBLE_STATUSES, as recording a payment does.
     *
     * @template TResult
     *
     * @param  Closure(Loan): TResult  $read
     * @param  list<string>  $statuses
     * @return TResult
     */
    public function rolledBackUnderPaymentLock(Loan $loan, Closure $read, array $statuses = Loan::ACTIVE_STATUSES): mixed
    {
        return LoanWriteTransaction::rolledBack(function () use ($loan, $read, $statuses): mixed {
            $this->lockLoanForPayment($loan, $statuses);

            return $read($loan);
        });
    }

    /**
     * What a payment is measured against for its badge: every open period due
     * on or before the payment date, or the next instalment if none is due yet.
     *
     * @return list<int>
     */
    private function owedScheduleIds(Loan $loan, Carbon $paymentDate): array
    {
        return self::owedAmong(
            $loan->amortizationSchedules()
                ->whereIn('status', AmortizationSchedule::UNPAID_STATUSES)
                ->orderBy('period_number')
                ->get(),
            $paymentDate,
        );
    }

    /**
     * owedScheduleIds() over open periods already read, in period order: the
     * ones due on or before the payment date, or the first if none is due.
     *
     * @param  EloquentCollection<int, AmortizationSchedule>  $open
     * @return list<int>
     */
    private static function owedAmong(EloquentCollection $open, Carbon $paymentDate): array
    {
        $due = $open->filter(fn (AmortizationSchedule $s) => $s->due_date->lte($paymentDate));

        return ($due->isNotEmpty() ? $due : $open->take(1))->pluck('id')->values()->all();
    }

    /**
     * Each period a previewed payment reaches, read from the allocation rows
     * processRepayment() wrote, and what that period would still owe after it.
     *
     * @return list<array{schedule_id: int, period: int, due_date: string, penalty: float, interest: float, principal: float, amount_applied: float, remaining_balance: float}>
     */
    private function previewedAllocations(Repayment $repayment): array
    {
        $allocations = $repayment->allocations()->orderBy('period_number')->get();
        $schedules = AmortizationSchedule::whereIn('id', $allocations->pluck('amortization_schedule_id'))->get()->keyBy('id');

        return $allocations->map(function (RepaymentAllocation $allocation) use ($schedules): array {
            $schedule = $schedules[$allocation->amortization_schedule_id];

            return [
                'schedule_id' => $schedule->id,
                'period' => $allocation->period_number,
                'due_date' => $schedule->due_date->toDateString(),
                'penalty' => (float) $allocation->penalty,
                'interest' => (float) $allocation->interest,
                'principal' => (float) $allocation->principal,
                'amount_applied' => round((float) $allocation->penalty + (float) $allocation->interest + (float) $allocation->principal, 2),
                'remaining_balance' => round(
                    max(0, (float) $schedule->principal_due - (float) $schedule->principal_paid)
                    + max(0, (float) $schedule->interest_due - (float) $schedule->interest_paid)
                    + max(0, (float) $schedule->penalty_amount - (float) $schedule->penalty_paid),
                    2,
                ),
            ];
        })->all();
    }

    /**
     * The stored `payment_type` of a payment on a loan with a Share Capital
     * Build-Up, decided exactly as the preview's badge (paymentBadge()) is, so
     * the two always agree.
     *
     * - Advance when the payment reaches any period past what is owed
     *   (owedAmong(): the periods due on or before the payment date, or the
     *   first open one when none is due yet), whatever the build-up.
     * - Exact when every owed period is settled, no insurance premium is left
     *   owed, and the overpayment is nothing or exactly the build-up of those
     *   periods (overpaymentIsTheBuildUp()), the figure amountDueNow() adds.
     * - Advance when every owed period is settled and no premium is left owed
     *   but the overpayment is not exactly that build-up: more or less than
     *   it, or a non-member's extra, which is never credited to share capital.
     * - Partial otherwise: an owed period or the premium left unpaid.
     *
     * `$schedules` are the open periods the allocation just wrote to, so their
     * status is what it left.
     *
     * @param  EloquentCollection<int, AmortizationSchedule>  $schedules
     * @param  list<int>  $owedIds
     * @param  list<int>  $allocatedIds
     */
    private function buildUpLoanPaymentType(Loan $loan, EloquentCollection $schedules, array $owedIds, array $allocatedIds, int $overpaymentCentavos, bool $premiumStillOwed): string
    {
        if (array_diff($allocatedIds, $owedIds) !== []) {
            return 'advance';
        }

        $owed = $schedules->whereIn('id', $owedIds);
        $owedPaid = ! $premiumStillOwed && $owed->every(fn (AmortizationSchedule $s) => $s->status === 'paid');

        if (! $owedPaid) {
            return 'partial';
        }

        if ($overpaymentCentavos === 0 || $this->overpaymentIsTheBuildUp($loan, $overpaymentCentavos, $owed)) {
            return 'exact';
        }

        return 'advance';
    }

    /**
     * Whether a payment's overpayment is exactly the share capital build-up of
     * the owed periods it settled, which the allocation credits to a member's
     * share capital (recordRepaymentOnLockedLoan()): the period's amount due
     * plus its build-up, or every overdue period plus one build-up each, the
     * amount amountDueNow() quotes.
     *
     * Only on a loan with a build-up and only for a member; a non-member's
     * extra is not credited and stays an ordinary overpayment. Compared in
     * whole centavos, against each period's build-up (PersistedScheduleFigures,
     * none on a period closed early). The callers check first that the payment
     * settled every owed period and reached none past them.
     *
     * @param  iterable<AmortizationSchedule>  $settled  the owed periods the payment settled
     */
    private function overpaymentIsTheBuildUp(Loan $loan, int $overpaymentCentavos, iterable $settled): bool
    {
        if ($overpaymentCentavos <= 0
            || (float) $loan->scb_amount <= 0
            || in_array($loan->borrower?->status, Borrower::NON_MEMBER_STATUSES, true)) {
            return false;
        }

        $buildUp = 0;

        foreach ($settled as $period) {
            $buildUp += PersistedScheduleFigures::shareCapitalBuildUpCentavos($loan, $period);
        }

        return $buildUp === $overpaymentCentavos;
    }

    /**
     * The payments page's badge for a previewed payment.
     *
     * Partial when what is owed (owedScheduleIds(), and any insurance premium
     * still owed on the loan) is not fully paid, advance
     * when it is and money went past it, to a later period or as an
     * overpayment, and exact otherwise. A member's overpayment of exactly the
     * build-up of the owed periods is passed as none
     * (overpaymentIsTheBuildUp()), and on a build-up loan the stored
     * `payment_type` is decided the same way (buildUpLoanPaymentType()).
     * Otherwise the stored `payment_type` keeps its own meaning, under which
     * anything paid before a period's due date is `advance`.
     *
     * @param  list<int>  $owedIds
     * @param  list<array{schedule_id: int}>  $allocations
     * @return array{type: string, label: string}
     */
    private function paymentBadge(array $owedIds, array $allocations, int $overpaymentCentavos, bool $premiumStillOwed): array
    {
        $owedPaid = ! $premiumStillOwed
            && ! AmortizationSchedule::whereIn('id', $owedIds)->where('status', '!=', 'paid')->exists();
        $wentPast = $overpaymentCentavos > 0
            || collect($allocations)->contains(fn (array $a) => ! in_array($a['schedule_id'], $owedIds, true));

        $type = match (true) {
            ! $owedPaid => 'partial',
            $wentPast => 'advance',
            default => 'exact',
        };

        return ['type' => $type, 'label' => self::PAYMENT_BADGES[$type]];
    }

    /**
     * Void a posted repayment and reverse its effects.
     *
     * The checks before the transaction refuse, with a 422, a void that was
     * wrong from the start. The transaction then locks in the order every loan
     * write takes (LoanWriteTransaction): the loan's collateral rows, the loan,
     * then this payment, and re-reads both under those locks
     * (lockVoidRows()), because two voids of one payment that both passed the
     * checks would otherwise both reverse its allocation. The second finds the
     * payment already voided and is answered with a 409, as is a deadlock or a
     * lock wait timeout, with nothing of it left.
     */
    public function voidRepayment(Repayment $repayment, string $reason, User $user): Repayment
    {
        if ($repayment->status !== 'posted') {
            throw ValidationException::withMessages([
                'repayment' => 'Only posted repayments can be voided.',
            ]);
        }

        // A restructured loan's schedule no longer describes the debt this
        // payment was made against: closing it deleted the unpaid rows and shrank
        // the partly-paid ones to exactly what had been collected. reverseAllocation()
        // would replay against those rewritten rows and resurrect a balance on a
        // loan that is closed and uncollectible, while `restructured_balance` and
        // `write_off_amount` silently stopped being true.
        if ($repayment->loan?->status === 'restructured') {
            throw ValidationException::withMessages([
                'loan' => 'This loan was closed by a restructure. Void the payment on the restructured loan instead, or reverse the restructure first.',
            ]);
        }

        // Hydrated OUTSIDE the transaction on purpose, so that reading it inside
        // fires no query. A plain SELECT as the transaction's first statement
        // would fix the consistent snapshot BEFORE the collateral lock below,
        // which is the exact defect this ordering exists to avoid. The guard at
        // :261 has already loaded it; this makes that guarantee explicit rather
        // than incidental. The allocations too, for the same reason: the guard
        // below reads them, and reverseAllocation() reads them again inside.
        $repayment->loadMissing(['loan', 'allocations']);

        $this->assertAllocationIsKnown($repayment);
        $this->assertNotAnExtensionCollection($repayment);

        // A deadlock or lock wait timeout is a 409, not a 500. This is the
        // outermost transaction: RepaymentController::void() and the test
        // helpers are the only callers.
        return LoanWriteTransaction::run(function () use ($repayment, $reason, $user) {
            $loan = $repayment->loan;

            // THE FIRST STATEMENT THAT TOUCHES THE DATABASE IN THIS TRANSACTION,
            // and it has to stay that way. Under REPEATABLE READ the consistent
            // snapshot is fixed by the first plain SELECT, and neither a locking
            // read nor DML moves it. reverseAllocation() below opens with a plain
            // `->get()` over the schedules — so with the lock taken after it, this
            // guard locked the right rows and then answered from a snapshot that
            // predated the lock, missing any pledge committed in between. That is
            // a live exploit, not a theoretical one: void a payment on a completed
            // loan, and while it is inside reverseAllocation() attach its
            // collateral to another active loan (permitted, because this loan is
            // not active yet). The guard would see no conflict and un-complete the
            // loan onto a collateral that now secures two live balances.
            //
            // Locking here means that attach cannot commit until this transaction
            // ends, so every later snapshot already contains everything the
            // assertion needs to see.
            //
            // Unconditional, even though only a `completed` loan goes on to
            // transition. Gating it on the same status test used below would
            // save a lock on the common path, at the cost of two conditions that
            // must be kept in agreement forever — and getting them out of step
            // reopens exactly this defect. Voids are rare; the lock is cheap.
            $lockedCollateralIds = CollateralPledgeGuard::lockCollateralsOf($loan);

            // Then the loan, then this payment, each re-read under its lock.
            $this->lockVoidRows($repayment, $loan);

            // A completed loan goes back to Defaulted when the payment that
            // completed it was made while it was defaulted (a recovery), read
            // before this payment is voided. Its penalty stays frozen, as on
            // any defaulted loan.
            $reopensDefaulted = $loan->status === 'completed' && $this->completedWhileDefaulted($loan);

            $this->reverseAllocation($repayment, penaltyFrozen: self::penaltyIsFrozen($loan) || $reopensDefaulted);

            // The insurance premium this payment collected is owed again,
            // exactly that much, on the loan row locked above.
            $insuranceApplied = LoanService::toCentavos((float) $repayment->insurance_applied);

            if ($insuranceApplied > 0) {
                $loan->update([
                    'insurance_remaining_balance' => (LoanService::toCentavos((float) $loan->insurance_remaining_balance) + $insuranceApplied) / 100,
                ]);
            }

            // Reverse whatever share capital this repayment credited — a no-op
            // if it credited none. Blocks the whole void (throws, rolling back
            // reverseAllocation() above too) if reversing would take the
            // member's share capital balance negative.
            $this->reverseShareCapitalCredit($repayment, $loan, $user);

            $repayment->update([
                'status' => 'voided',
                'void_reason' => $reason,
                'voided_by' => $user->id,
                'voided_at' => now(),
            ]);

            /*
             * Undo the collection in the books, in the same transaction that
             * undoes it in the portfolio.
             *
             * A REVERSAL, not a delete: a posted entry is never edited or
             * removed, so the original collection and its mirror both stay on
             * the record and net to zero. That is what makes the void auditable
             * rather than merely current — and JournalPoster::reverse() is the
             * only thing that knows how to build one from the STORED lines.
             *
             * Without this, voiding a payment would leave the collection on the
             * books forever: cash and income overstated by the whole payment,
             * the loan balance restored by reverseAllocation() above, and the
             * two silently disagreeing. Returns null, harmlessly, for a payment
             * taken before this organisation adopted accounting.
             */
            app(AutomaticPoster::class)->reverseFor(
                $repayment,
                'loan_collection',
                $user->id,
                $reason,
            );

            // If loan was completed, revert appropriately based on remaining payments
            if ($loan->status === 'completed') {
                $remainingPayments = $loan->repayments()
                    ->where('status', 'posted')
                    ->where('id', '!=', $repayment->id)
                    ->count();

                // `completed` → `ongoing`/`released` is a transition INTO
                // Loan::PLEDGING_STATUSES, and it writes no `loan_collaterals`
                // row, so the guard on CollateralController::attach() cannot
                // see it. This is the REACHABLE double pledge, reachable
                // without anybody doing anything unusual: that guard
                // deliberately permits an attach whose only other holder is
                // `completed`, so a sanctioned attach to a second loan followed
                // by a sanctioned void of a payment on the first leaves one
                // collateral securing two live balances.
                //
                // Asserted before the status write; the lock it relies on was
                // taken at the top of the transaction. A throw rolls the whole
                // void back — the repayment stays `posted` and the schedules
                // stay as reverseAllocation() found them — which is the right
                // failure: refuse to un-complete the loan rather than
                // un-complete it into an unenforceable state.
                CollateralPledgeGuard::assertNoDoublePledge($lockedCollateralIds, $loan);

                if ($reopensDefaulted) {
                    // A recovery that is voided leaves the default in place;
                    // nothing moves a defaulted loan back to ongoing.
                    $loan->update(['status' => 'defaulted']);
                } else {
                    $loan->update(['status' => $remainingPayments > 0 ? 'ongoing' : 'released']);
                }
            }

            // Sent once the void commits; a void that rolls back tells nobody.
            app(Notifications::class)->paymentVoided($repayment, $user, $reason);

            return $repayment;
        });
    }

    /**
     * Whether the payment that completed `$loan` was recorded while the loan
     * was defaulted: its latest recorded posted payment (by id, not payment
     * date, which can be backdated), since a completed loan takes no more.
     * Payments recorded before `loan_status_before` existed read null, and
     * none of them could be made on a defaulted loan.
     */
    private function completedWhileDefaulted(Loan $loan): bool
    {
        return $loan->repayments()
            ->where('status', 'posted')
            ->reorder('id', 'desc')
            ->value('loan_status_before') === 'defaulted';
    }

    /**
     * Lock the loan row, then the payment's, after the collateral rows
     * voidRepayment() locked first, and refuse with a 409 if either changed
     * since the request read them.
     *
     * Two locking reads, so neither fixes the transaction's snapshot, and the
     * order every loan write takes (LoanWriteTransaction). `$loan` and
     * `$repayment` are then refreshed from their locked rows, so everything
     * below starts from what is committed rather than from what the request
     * read before the lock: a payment no longer posted was voided by another
     * request, and a loan now `restructured` was closed by a restructure's
     * release, in between.
     *
     * The allocations are read again too, as the transaction's first plain
     * read, now that every lock is held. A period this payment paid that a
     * restructure or an extension replaced in between has lost its schedule,
     * and reversing onto it is the same conflict.
     *
     * Then, with every lock held, a payment made before the loan was
     * rescheduled is refused (assertNotRescheduledSincePaid()).
     *
     * @throws HttpResponseException 409 when the payment or its loan changed since it was read
     * @throws ValidationException 422 on `repayment` when the loan was rescheduled after the payment
     */
    private function lockVoidRows(Repayment $repayment, Loan $loan): void
    {
        $lockedLoan = Loan::whereKey($loan->getKey())->lockForUpdate()->first();
        $lockedRepayment = Repayment::whereKey($repayment->getKey())->lockForUpdate()->first();

        if ($lockedLoan === null || $lockedLoan->status === 'restructured'
            || $lockedRepayment === null || $lockedRepayment->status !== 'posted') {
            throw LoanWriteTransaction::conflict();
        }

        $loan->setRawAttributes($lockedLoan->getAttributes(), true);
        $repayment->setRawAttributes($lockedRepayment->getAttributes(), true);
        $repayment->load('allocations');

        if ($repayment->allocations->contains(fn (RepaymentAllocation $allocation) => $allocation->amortization_schedule_id === null)) {
            throw LoanWriteTransaction::conflict();
        }

        $this->assertNotRescheduledSincePaid($repayment, $loan);
    }

    /**
     * Refuse to void a payment made before the loan was extended, restructured
     * or given more terms.
     *
     * A reschedule closes the open periods money was collected on at what was
     * collected, and works out the new periods, their interest and the
     * principal it carries from the balance the earlier payments left.
     * Reversing one of those payments afterwards would reopen a period the
     * reschedule closed and leave the new terms resting on money that was
     * never collected. So a payment that paid a period an adjustment closed
     * (`closed_by_adjustment_id`), or any payment made before an applied
     * extension, restructure or term extension, stays; staff correct the loan
     * with a balance adjustment.
     *
     * Penalty waivers and balance adjustments close no periods, so they don't
     * count. A reschedule applied in the same second as the payment counts,
     * because the two can't be ordered. Read under the loan lock every
     * adjustment takes, so one applied concurrently has either committed
     * before this read or waits for the void to finish.
     *
     * @throws ValidationException on `repayment`
     */
    private function assertNotRescheduledSincePaid(Repayment $repayment, Loan $loan): void
    {
        $paidAClosedPeriod = AmortizationSchedule::whereKey($repayment->allocations->pluck('amortization_schedule_id')->unique()->all())
            ->whereNotNull('closed_by_adjustment_id')
            ->exists();

        $rescheduledSince = $paidAClosedPeriod || LoanAdjustment::where('loan_id', $loan->id)
            ->where('status', 'applied')
            ->whereIn('adjustment_type', ['extension', 'restructure', 'term_extension'])
            ->where('applied_at', '>=', $repayment->created_at)
            ->exists();

        if ($rescheduledSince) {
            throw ValidationException::withMessages([
                'repayment' => 'This payment was made before the loan was extended or restructured, so it cannot be voided. Use a balance adjustment to correct the loan instead.',
            ]);
        }
    }

    /**
     * Reverse the share capital this repayment credited, if any.
     *
     * No-op when this repayment never credited share capital — either its
     * loan carried no `scb_amount`, or it predates this feature. Otherwise
     * inserts an offsetting debit row for the credited amount, UNLESS doing
     * so would take the member's share capital balance negative, in which
     * case the void is refused outright.
     *
     * The balance is `SUM(credit) - SUM(debit)` across the member's whole
     * `share_capital_ledger` history — the same computation
     * ReportService::shareCapital() and shareCapitalByMember() use for the
     * Share Capital report, so a void is judged against the same figure the
     * report would show, not a second definition of "balance" that could
     * drift from it.
     *
     * @throws ValidationException naming the shortfall, on `share_capital`
     */
    private function reverseShareCapitalCredit(Repayment $repayment, Loan $loan, User $user): void
    {
        $credit = ShareCapitalLedger::where('repayment_id', $repayment->id)
            ->where('credit', '>', 0)
            ->first();

        if (! $credit) {
            return;
        }

        $creditedAmount = (float) $credit->credit;

        // Locking, not plain: this is a check-then-act against a hard
        // non-negative invariant, so a second void (or a fresh credit) for the
        // same borrower racing this one must wait rather than both read the
        // same pre-reversal balance and both pass. `borrower_id` is indexed,
        // so under InnoDB's default REPEATABLE READ this also gap-locks
        // against a concurrent INSERT for this borrower, not just existing
        // rows. A locking read always answers from the latest committed data
        // regardless of this transaction's snapshot, so it is safe this far
        // into voidRepayment() rather than needing to be its first statement —
        // see CollateralPledgeGuard's docblock for the guard shape this would
        // need if it were a plain read instead.
        $currentBalance = (float) ShareCapitalLedger::where('borrower_id', $loan->borrower_id)
            ->lockForUpdate()
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) as balance')
            ->value('balance');

        $balanceAfterReversal = round($currentBalance - $creditedAmount, 2);

        if ($balanceAfterReversal < 0) {
            throw ValidationException::withMessages([
                'share_capital' => sprintf(
                    'Voiding this payment would reverse a share capital credit of %s, but the '
                        .'member\'s current share capital balance is only %s — a shortfall of %s. '
                        .'The void is blocked to avoid taking their balance negative.',
                    number_format($creditedAmount, 2),
                    number_format($currentBalance, 2),
                    number_format(abs($balanceAfterReversal), 2),
                ),
            ]);
        }

        ShareCapitalLedger::create([
            'borrower_id' => $loan->borrower_id,
            'repayment_id' => $repayment->id,
            // As the credit's own reference; a payment is voided once.
            'reference' => "SCV-{$repayment->receipt_number}",
            'date' => now()->toDateString(),
            'description' => "Void reversal of share capital build-up from payment {$repayment->receipt_number}",
            'debit' => $creditedAmount,
            'credit' => 0,
            'created_by' => $user->id,
        ]);
    }

    /**
     * Return a summary of the loan's current balance state.
     */
    public function getLoanSummary(Loan $loan): array
    {
        $today = Carbon::today();

        $schedules = $loan->amortizationSchedules()->with(['principalChanges', 'postedPrincipalPayments'])->get();

        $totalDue = $schedules->sum('total_due');
        $totalPrincipalPaid = $schedules->sum('principal_paid');
        $totalInterestPaid = $schedules->sum('interest_paid');
        $totalPenaltyPaid = $schedules->sum('penalty_paid');
        $totalPenaltyDue = $schedules->sum('penalty_amount');
        $totalPaid = $totalPrincipalPaid + $totalInterestPaid + $totalPenaltyPaid;

        // Loan::owedCentavos(), the rule LoanResource states the same figures by.
        $owed = $loan->owedCentavos($schedules);
        $takesPayments = in_array($loan->status, Loan::COLLECTIBLE_STATUSES, true);

        // Late, not merely due — same cutoff and same helper as
        // LoanResource's `overdue_amount`, so the loans list and the loan
        // detail screen cannot report different arrears for one loan.
        $overdueSchedules = AmortizationSchedule::lateUnpaid($schedules, $loan->grace_period_days, $today);
        $overdueAmount = $overdueSchedules->sum(fn ($s) => (
            max(0, (float) $s->principal_due - (float) $s->principal_paid)
            + max(0, (float) $s->interest_due - (float) $s->interest_paid)
            + max(0, (float) $s->penalty_amount - (float) $s->penalty_paid)
        ));

        $nextSchedule = $schedules
            ->filter(fn ($s) => in_array($s->status, ['pending', 'partial', 'overdue']))
            ->sortBy('due_date')
            ->first();

        $totalRepaid = $loan->repayments()->where('status', 'posted')->sum('amount_paid');

        return [
            'loan_id' => $loan->id,
            'loan_account_number' => $loan->loan_account_number,
            'status' => $loan->status,
            'principal_amount' => (float) $loan->principal_amount,
            'total_due' => round($totalDue, 2),
            'total_paid' => round($totalPaid, 2),
            'total_repaid' => round($totalRepaid, 2),
            // Paid breakdown — frontend renders each as its own row
            'principal_paid' => round((float) $totalPrincipalPaid, 2),
            'interest_paid' => round((float) $totalInterestPaid, 2),
            'penalty_paid' => round((float) $totalPenaltyPaid, 2),
            'outstanding_principal' => $owed['principal'] / 100,
            'outstanding_interest' => $owed['interest'] / 100,
            'outstanding_penalty' => $owed['penalty'] / 100,
            // Frontend reads `penalty_amount` meaning the remaining penalty to collect.
            'penalty_amount' => $owed['penalty'] / 100,
            // The most monthly charges counted in any open late period's
            // penalty, as last charged (applyPenalties()): the screen's
            // "Penalty: N month(s)".
            'penalty_months' => (int) ($overdueSchedules->max('penalty_months') ?? 0),
            // The insurance premium the release left owed, still owed: part of
            // what the loan owes, as in LoanResource's `total_payable`.
            'insurance_owed' => max(0, LoanService::toCentavos((float) $loan->insurance_remaining_balance)) / 100,
            // The most a restructure may take as the new loan's principal
            // (LoanService::restructurableCentavos()): the open periods' principal,
            // interest and penalty, without the premium, which moves to the
            // new loan as premium owed. The restructure form's starting figure.
            'restructurable_balance' => LoanService::restructurableCentavos($schedules) / 100,
            // Everything owed: principal, interest and penalty plus the premium
            // owed. LoanResource's `total_outstanding` for the same loan.
            'total_outstanding' => $owed['total'] / 100,
            // Deprecated alias of `total_outstanding`, kept one release.
            'outstanding_balance' => $owed['total'] / 100,
            // Only for a loan that takes payments (Loan::COLLECTIBLE_STATUSES); a
            // payment on any other is refused, so there is nothing to preset.
            'advance_presets' => $takesPayments ? $this->advancePresets($loan, $schedules, $today) : [],
            // Null, too, on a loan with a Share Capital Build-Up: one payment
            // can't settle it there (the allocation stops after the current
            // period), so the page hides "Pay all remaining".
            'payoff_amount' => $takesPayments && (float) $loan->scb_amount <= 0
                ? $this->payoffCentavos($loan, $schedules, $today) / 100
                : null,
            'overdue_amount' => round($overdueAmount, 2),
            'overdue_schedules_count' => $overdueSchedules->count(),
            'next_due_date' => $nextSchedule?->due_date->toDateString(),
            'next_due_amount' => $nextSchedule ? round(
                max(0, (float) $nextSchedule->principal_due - (float) $nextSchedule->principal_paid)
                + max(0, (float) $nextSchedule->interest_due - (float) $nextSchedule->interest_paid)
                + max(0, (float) $nextSchedule->penalty_amount - (float) $nextSchedule->penalty_paid),
                2,
            ) : null,
        ];
    }

    /**
     * The Record Payment presets: one `{periods, amount}` per open period, in
     * the order the allocation takes money (recordRepaymentOnLockedLoan()).
     * Preset n is what a payment on `$asOf` must be to leave the first n open
     * periods paid: the premium owed (taken first), then period by period its
     * penalty as the payment will charge it (amountDueNow()'s rule), and its
     * remaining interest and principal, in whole centavos.
     *
     * On a loan with a Share Capital Build-Up only the 1-period preset is
     * sent: there the allocation stops after the current period and turns the
     * rest into share capital, so "n periods" would not prepay. That preset
     * also carries the period's build-up (an open period's is never credited
     * yet), so a member keeps contributing it, by amountDueNow()'s rule: only
     * when the period is the one the allocation treats as current, the only
     * one after which money becomes share capital, and only for a member.
     * Paying it leaves the period paid and credits exactly the build-up.
     *
     * @param  EloquentCollection<int, AmortizationSchedule>  $schedules
     * @return list<array{periods: int, amount: float}>
     */
    private function advancePresets(Loan $loan, EloquentCollection $schedules, Carbon $asOf): array
    {
        $running = max(0, LoanService::toCentavos((float) $loan->insurance_remaining_balance));
        $presets = [];

        foreach ($this->openPeriodsOwedCentavos($loan, $schedules, $asOf) as $index => $owed) {
            $running += $owed;
            $presets[] = ['periods' => $index + 1, 'amount' => $running / 100];
        }

        if ((float) $loan->scb_amount <= 0 || $presets === []) {
            return $presets;
        }

        $paymentDate = $asOf->copy()->startOfDay();
        $open = $schedules
            ->filter(fn (AmortizationSchedule $s) => in_array($s->status, AmortizationSchedule::UNPAID_STATUSES, true))
            ->sortBy('period_number')
            ->values();
        $first = $open->first();
        // The same "current" period the allocation picks.
        $current = $open->first(fn (AmortizationSchedule $s) => $s->due_date->gte($paymentDate)) ?? $open->last();

        if ($first->id === $current->id && ! in_array($loan->borrower?->status, Borrower::NON_MEMBER_STATUSES, true)) {
            $running = LoanService::toCentavos($presets[0]['amount']) + PersistedScheduleFigures::shareCapitalBuildUpCentavos($loan, $first);

            return [['periods' => 1, 'amount' => $running / 100]];
        }

        return array_slice($presets, 0, 1);
    }

    /**
     * Everything the loan owes on `$asOf`, in whole centavos: the premium owed
     * plus every open period's penalty (as the payment will charge it),
     * interest and principal. The stored `total_outstanding` unless a period
     * fell late past its grace since penalties were last stamped, which this
     * counts so the figure clears the loan in one payment.
     *
     * Only for a loan without a Share Capital Build-Up (getLoanSummary() sends
     * null otherwise): with one, the allocation stops after the current period
     * (recordRepaymentOnLockedLoan()), so recording this amount would pay the
     * current period and any overdue ones and credit the rest to a member's
     * share capital instead of settling the loan.
     *
     * @param  EloquentCollection<int, AmortizationSchedule>  $schedules
     */
    private function payoffCentavos(Loan $loan, EloquentCollection $schedules, Carbon $asOf): int
    {
        return max(0, LoanService::toCentavos((float) $loan->insurance_remaining_balance))
            + array_sum($this->openPeriodsOwedCentavos($loan, $schedules, $asOf));
    }

    /**
     * Each open period's penalty (penaltyAsOf(), or the stored charge where it
     * leaves the period alone), interest and principal still owed on `$asOf`,
     * in whole centavos, in period order. Reads only.
     *
     * @param  EloquentCollection<int, AmortizationSchedule>  $schedules
     * @return list<int>
     */
    private function openPeriodsOwedCentavos(Loan $loan, EloquentCollection $schedules, Carbon $asOf): array
    {
        $centavos = fn (mixed $pesos): int => LoanService::toCentavos((float) ($pesos ?? 0));
        $paymentDate = $asOf->copy()->startOfDay();

        return $schedules
            ->filter(fn (AmortizationSchedule $s) => in_array($s->status, AmortizationSchedule::UNPAID_STATUSES, true))
            ->sortBy('period_number')
            ->map(function (AmortizationSchedule $period) use ($loan, $paymentDate, $centavos): int {
                $charged = $this->penaltyAsOf($loan, $period, $paymentDate) ?? (float) $period->penalty_amount;

                return max(0, $centavos($charged) - $centavos($period->penalty_paid))
                    + max(0, $centavos($period->interest_due) - $centavos($period->interest_paid))
                    + max(0, $centavos($period->principal_due) - $centavos($period->principal_paid));
            })
            ->values()
            ->all();
    }

    /**
     * Compute and apply penalty_amount to overdue schedules (non-destructive on paid amount).
     *
     * Only schedules past the loan's contractual grace period are penalised or
     * stamped `overdue`. That grace is copied onto the loan at creation and
     * printed on the borrower's promissory note and disclosure statement, and
     * until this comparison moved it was honoured by nothing here — a borrower
     * inside the window their own paperwork granted them was charged anyway,
     * and their schedule was labelled overdue on the screen.
     *
     * The cutoff comes from AmortizationSchedule::pastGraceCutoff() rather than
     * being written out here, so this, the loans:apply-penalties pre-filter and
     * Loan::scopePastDue() cannot drift on what late means. The loan is already
     * in hand, so shifting the cutoff back by the grace days leaves `due_date`
     * bare and indexable instead of wrapping it in date arithmetic.
     *
     * No grace (0 or null) reproduces the previous behaviour exactly.
     *
     * Schedules that predate `loans.imported_arrears_baseline` are skipped
     * outright — see AmortizationSchedule::penalisableSql(). THIS is the method
     * that exclusion has to reach: it is the only code anywhere that WRITES a
     * penalty, and processRepayment() calls it as its first step inside the
     * transaction on every single payment. The nightly loans:apply-penalties
     * command never touches a loan whose arrears are all pre-import, so without
     * the check here the first peso a migrated member paid would penalise their
     * entire imported backlog, from a path that command never runs.
     *
     * The loan is in hand, so the PHP twin AmortizationSchedule::isPenalisable()
     * decides it rather than a hand-rolled date comparison. The rule is applied
     * per schedule rather than as a second query cutoff because it is a
     * property of the row's own due date, and a null baseline — every loan not
     * imported — makes it true, so nothing branches on whether a loan was
     * imported.
     *
     * A consequence worth stating: because this is the only thing that stamps
     * `overdue`, pre-baseline schedules stay `pending`/`partial` forever. That
     * is fine. Loan::scopePastDue(), the Due/Past Due report and the aging
     * report all read `due_date` directly rather than the stamp, so imported
     * arrears still show up everywhere the coop needs to chase them — they are
     * simply never charged for a second time.
     *
     * A period a waiver made before the monthly penalty forgave
     * (AmortizationSchedule::isWaivedForGood()) is skipped too, and for good:
     * this method overwrites the charge rather than adding to it, so passing
     * over it once would only have moved the waiver's undoing to the next
     * payment or the next night. A period waived since is charged again for
     * the months after its waiver (penaltyStateAsOf()).
     *
     * It overwrites the charge with penaltyStateAsOf()'s figure as of
     * `$asOfDate`, and the number of charges in it, so running it twice on
     * one day charges the month once. `$markOverdue` false leaves each
     * period's status as it is: the re-charge to today after a backdated
     * payment (recordRepaymentOnLockedLoan()).
     *
     * On a defaulted loan (penaltyIsFrozen()) it charges nothing new and
     * leaves each status as it is: each period owes the lesser of its
     * `$ceilings` entry (penaltyCeilings(), what it was charged before the
     * default; the stored charge without one) and the charge as of
     * `$asOfDate`, never below what was paid.
     *
     * @param  array<int, array{amount: float, months: int}>|null  $ceilings  by schedule id
     */
    public function applyPenalties(Loan $loan, Carbon $asOfDate, bool $markOverdue = true, ?array $ceilings = null): void
    {
        if (! PenaltyTerms::chargesPenalty($loan)) {
            return;
        }

        $markOverdue = $markOverdue && ! self::penaltyIsFrozen($loan);

        $lateBefore = AmortizationSchedule::pastGraceCutoff($loan->grace_period_days, $asOfDate);

        $loan->amortizationSchedules()
            ->where('due_date', '<', $lateBefore)
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->whereRaw(AmortizationSchedule::stillChargeableSql())
            ->with(['principalChanges', 'postedPrincipalPayments'])
            ->each(function (AmortizationSchedule $schedule) use ($loan, $asOfDate, $markOverdue, $ceilings) {
                $state = $this->penaltyStateAsOf($loan, $schedule, $asOfDate, $ceilings[$schedule->id] ?? null);

                if ($state === null) {
                    return;
                }

                $schedule->update([
                    'penalty_amount' => $state['amount'],
                    'penalty_months' => $state['months'],
                    ...($markOverdue ? ['status' => 'overdue'] : []),
                ]);
            });
    }

    /**
     * The penalty applyPenalties() charges `$schedule` as of `$asOfDate`, or
     * null when it leaves the period alone: no penalty rate, a closed period
     * or one waived for good, one not yet late past its grace, or one due
     * before the loan's imported arrears baseline. Pure: writes nothing, so a
     * preview (AutoPayService::preview()) quotes what a payment will charge.
     */
    public function penaltyAsOf(Loan $loan, AmortizationSchedule $schedule, Carbon $asOfDate): ?float
    {
        return $this->penaltyStateAsOf($loan, $schedule, $asOfDate)['amount'] ?? null;
    }

    /**
     * The penalty a late period owes on `$asOf` and the number of monthly
     * charges counted in it (`penalty_months`), or null where penaltyAsOf()
     * is. On a defaulted loan (penaltyIsFrozen()) it is frozenState(): never
     * more than was charged before the default. One charge of the loan's
     * `penalty_rate` percent of the principal
     * then unpaid on it, to the centavo, for each month it is late (owner's
     * answer, Arvin, 2026-11-11).
     *
     * - The first charge falls on the first day past grace, the next ones at
     *   each full month after the due date: max(1, full months since the due
     *   date) charges. 1 month late is 1 charge, 3 months late is 3.
     * - Each charge is worked on the principal unpaid on its own day, so a
     *   partial principal payment lowers the months after it and never the
     *   ones before (principalUnpaidOn()).
     * - A period already late before the switch date (LatePenaltySetting)
     *   keeps the one charge it had, on the principal unpaid that day, plus
     *   one for each full month after the switch date.
     * - A waiver's checkpoint (penaltyCheckpoint(), option b) fixes what the
     *   period kept on its day, and how many charges that was. Only the
     *   period's own charges after that day are added, so the next falls on
     *   its next monthly charge day. As of a day before the waiver, the
     *   charges up to that day count, never more than the waiver kept.
     * - A balance adjustment needs no checkpoint: each month is charged on
     *   the principal owed on its own day (principalUnpaidOn()), so the months
     *   before it keep the old principal.
     * - The floor at what was paid applies to the whole figure.
     *
     * The one definition of the charge. applyPenalties() writes it on every
     * late period, every preview reads it through penaltyAsOf(), and
     * reverseAllocation() puts it back on a period a void reopens, so none of
     * them can disagree about what a period owes.
     *
     * Never below what was already paid toward it: a charge under its own
     * payments would leave a receipt carrying penalty the period no longer
     * says was owed.
     *
     * @param  array{amount: float, months: int}|null  $ceiling  a defaulted loan's charge before the default (frozenState())
     * @return array{amount: float, months: int}|null
     */
    public function penaltyStateAsOf(Loan $loan, AmortizationSchedule $schedule, Carbon $asOf, ?array $ceiling = null): ?array
    {
        $state = $this->monthlyPenaltyStateAsOf($loan, $schedule, $asOf);

        return $state !== null && self::penaltyIsFrozen($loan)
            ? $this->frozenState($schedule, $state, $ceiling)
            : $state;
    }

    /**
     * penaltyStateAsOf() by the monthly rule alone, whatever the loan's status.
     *
     * @return array{amount: float, months: int}|null
     */
    private function monthlyPenaltyStateAsOf(Loan $loan, AmortizationSchedule $schedule, Carbon $asOf): ?array
    {
        if (! PenaltyTerms::chargesPenalty($loan)
            || $schedule->isWaivedForGood()
            || ! in_array($schedule->status, ['pending', 'partial', 'overdue'], true)
            || ! $schedule->due_date->lt(AmortizationSchedule::pastGraceCutoff($loan->grace_period_days, $asOf))
            || ! AmortizationSchedule::isPenalisable($loan->imported_arrears_baseline, $schedule->due_date)) {
            return null;
        }

        $charges = collect($this->penaltyCharges($loan, $schedule, $asOf));

        $checkpointOn = $schedule->penalty_checkpoint_on?->copy()->startOfDay();

        if ($checkpointOn !== null && $asOf->copy()->startOfDay()->lt($checkpointOn)) {
            // As of a day before the waiver: the charges up to that day, never
            // more than the waiver kept (a payment or void dated before it).
            $charged = min($charges->sum('centavos'), LoanService::toCentavos((float) $schedule->penalty_checkpoint_amount));
            $months = $charges->count();
        } elseif ($checkpointOn !== null) {
            $after = $charges->filter(fn (array $charge): bool => $charge['on']->gt($checkpointOn));
            $charged = LoanService::toCentavos((float) $schedule->penalty_checkpoint_amount) + $after->sum('centavos');
            $months = (int) $schedule->penalty_checkpoint_months + $after->count();
        } else {
            $charged = $charges->sum('centavos');
            $months = $charges->count();
        }

        return [
            'amount' => max($charged / 100, (float) $schedule->penalty_paid),
            'months' => $months,
        ];
    }

    /**
     * Whether a loan's late penalty is frozen: a defaulted loan is charged no
     * new penalty (round Y, until the owner decides whether penalty keeps
     * running on defaulted loans). The nightly run already skips defaulted
     * loans, and this keeps a recovery payment from charging the missed months
     * in one go, so its preview, the payment and the summary agree. Penalty
     * charged before the default stays owed.
     *
     * The one rule: penaltyStateAsOf() gives such a loan's periods
     * frozenState(), so applyPenalties() never charges more than was charged
     * before the default and penaltyAsOf() readers agree with the payment;
     * voidRepayment() keeps the stored charge for a loan the void returns to
     * defaulted.
     */
    public static function penaltyIsFrozen(Loan $loan): bool
    {
        return $loan->status === 'defaulted';
    }

    /**
     * A defaulted loan's period penalty as of a date: the lesser of what it
     * was charged before the default (`$ceiling`, else its stored charge) and
     * `$monthly`, what the monthly rule gives as of that date, never below
     * what was paid. So a payment dated before a charge the period already
     * holds doesn't pay that charge, which stays owed, and nothing is charged
     * past the default.
     *
     * @param  array{amount: float, months: int}  $monthly
     * @param  array{amount: float, months: int}|null  $ceiling
     * @return array{amount: float, months: int}
     */
    private function frozenState(AmortizationSchedule $schedule, array $monthly, ?array $ceiling): array
    {
        $ceiling ??= ['amount' => (float) $schedule->penalty_amount, 'months' => (int) $schedule->penalty_months];
        $paid = (float) $schedule->penalty_paid;

        if (LoanService::toCentavos($monthly['amount']) >= LoanService::toCentavos($ceiling['amount'])) {
            return ['amount' => max($ceiling['amount'], $paid), 'months' => $ceiling['months']];
        }

        return ['amount' => max($monthly['amount'], $paid), 'months' => min($monthly['months'], $ceiling['months'])];
    }

    /**
     * Each period's penalty as stored now, by schedule id: on a defaulted
     * loan, what it was charged before the default (the nightly run skips
     * defaulted loans), read before a payment's own penalty step moves it.
     *
     * @return array<int, array{amount: float, months: int}>
     */
    private function penaltyCeilings(Loan $loan): array
    {
        return $loan->amortizationSchedules()->get(['id', 'penalty_amount', 'penalty_months'])
            ->mapWithKeys(fn (AmortizationSchedule $schedule): array => [
                $schedule->id => ['amount' => (float) $schedule->penalty_amount, 'months' => (int) $schedule->penalty_months],
            ])
            ->all();
    }

    /**
     * A waiver's checkpoint (option b): charge `$schedule` up to `$on` and
     * keep `$keep` (what was paid, the charge the waiver leaves) with the
     * number of charges so far, so later months are added from there. Sets
     * the attributes and leaves the save to the caller. Returns false,
     * writing nothing, on a period penaltyStateAsOf() leaves alone: one not
     * late yet, or one waived for good, which stays so (option a).
     */
    public function penaltyCheckpoint(Loan $loan, AmortizationSchedule $schedule, Carbon $on, float $keep): bool
    {
        $state = $this->penaltyStateAsOf($loan, $schedule, $on);

        if ($state === null) {
            return false;
        }

        $amount = $keep;

        $schedule->fill([
            'penalty_amount' => $amount,
            'penalty_months' => $state['months'],
            'penalty_checkpoint_on' => $on->toDateString(),
            'penalty_checkpoint_amount' => $amount,
            'penalty_checkpoint_months' => $state['months'],
        ]);

        return true;
    }

    /**
     * Each monthly charge a late period has had by `$asOf`, in date order:
     * the day it fell, its amount in centavos, and which charge it is (1 for
     * the first). Waivers are not taken off: penaltyStateAsOf() does that, and the
     * Ledger shows them as their own lines.
     *
     * - The first charge falls on the first day past grace, then one on each
     *   monthly anniversary of the due date from the second month:
     *   max(1, full months since the due date) charges.
     * - A period already late before the switch date has its one charge (on
     *   the principal unpaid on the switch day, dated when it fell late), then
     *   one at each full month after the switch date.
     * - Each charge is the penalty rate on the principal unpaid on its own
     *   day (principalUnpaidOn()), to the centavo.
     * - The rate is the loan's `penalty_rate`, or, on a loan created under a
     *   penalty configuration (`penalty_brackets`), the rate of the bracket
     *   covering the charge's days past the due date (the day after it is
     *   day 1). A day no bracket covers, or one covered at 0%, is no charge
     *   and is left out (so it counts in no `penalty_months`); each charge
     *   keeps its number, and carries its `rate`. The switch-date rule above
     *   applies to bracket loans too, since it turns on when the period fell
     *   late (a CSV import or a backdated start can fall late before it): the
     *   pre-switch charge takes the bracket of the day it fell late.
     *
     * None on a loan that charges no penalty (PenaltyTerms::chargesPenalty()),
     * a period not late on `$asOf`, or one due before the loan's imported
     * arrears baseline.
     *
     * `$asCharged` works each charge as it was charged on its day: a payment
     * voided since still counts as paid on the days before its void. The
     * Ledger weighs the months it shows against the payments it shows with
     * it; every figure owed is worked without it, as if a void's payment never
     * was.
     *
     * @return list<array{on: Carbon, centavos: int, number: int, rate?: float}>
     */
    public function penaltyCharges(Loan $loan, AmortizationSchedule $schedule, Carbon $asOf, bool $asCharged = false): array
    {
        $rate = (float) $loan->penalty_rate;
        $brackets = PenaltyTerms::bracketsOf($loan);

        if (! PenaltyTerms::chargesPenalty($loan)
            || ! $schedule->due_date->lt(AmortizationSchedule::pastGraceCutoff($loan->grace_period_days, $asOf))
            || ! AmortizationSchedule::isPenalisable($loan->imported_arrears_baseline, $schedule->due_date)) {
            return [];
        }

        $day = $asOf->copy()->startOfDay();
        $unpaidOn = $this->principalUnpaidOn($schedule, $asCharged);
        $charge = fn (Carbon $base, float $rate): int => LoanService::toCentavos(round($unpaidOn($base) / 100 * ($rate / 100), 2));

        $dueDate = $schedule->due_date->copy()->startOfDay();
        $lateFrom = $dueDate->copy()->addDays(max(0, (int) $loan->grace_period_days) + 1);
        $days = self::chargeDays($dueDate, $lateFrom, $day);

        if ($brackets === null) {
            return array_map(
                fn (array $chargeDay): array => ['on' => $chargeDay['on'], 'centavos' => $charge($chargeDay['base'], $rate), 'number' => $chargeDay['number']],
                $days,
            );
        }

        $charges = [];

        foreach ($days as $chargeDay) {
            $bracketRate = BracketRate::rateFor($brackets, (int) $dueDate->diffInDays($chargeDay['on']));

            // A day no bracket covers, or one covered at 0%, is no charge.
            if ($bracketRate === null || $bracketRate <= 0) {
                continue;
            }

            $charges[] = [
                'on' => $chargeDay['on'],
                'centavos' => $charge($chargeDay['base'], $bracketRate),
                'number' => $chargeDay['number'],
                'rate' => $bracketRate,
            ];
        }

        return $charges;
    }

    /**
     * The days a late period is charged by `$day`, the principal unpaid on
     * which day each is worked on (`base`), and which charge it is.
     *
     * - The first falls on the first day past grace, then one on each monthly
     *   anniversary of the due date from the second month, each worked on its
     *   own day.
     * - A period already late before the switch date has its one charge,
     *   dated when it fell late and worked on the switch day, then one at
     *   each full month after the switch date.
     *
     * @return list<array{on: Carbon, base: Carbon, number: int}>
     */
    private static function chargeDays(Carbon $dueDate, Carbon $lateFrom, Carbon $day): array
    {
        $switchDate = LatePenaltySetting::monthlyFrom();

        if ($switchDate !== null && $lateFrom->lt($switchDate)) {
            $days = [['on' => $lateFrom, 'base' => $switchDate, 'number' => 1]];

            for ($month = 1; $month <= self::fullMonthsBetween($switchDate, $day); $month++) {
                $on = $switchDate->copy()->addMonthsNoOverflow($month);
                $days[] = ['on' => $on, 'base' => $on, 'number' => $month + 1];
            }

            return $days;
        }

        $days = [['on' => $lateFrom, 'base' => $lateFrom, 'number' => 1]];

        for ($month = 2; $month <= self::fullMonthsBetween($dueDate, $day); $month++) {
            $on = $dueDate->copy()->addMonthsNoOverflow($month);
            $on = $on->lt($lateFrom) ? $lateFrom : $on;
            $days[] = ['on' => $on, 'base' => $on, 'number' => $month];
        }

        return $days;
    }

    /**
     * The principal unpaid on a period on a given day, in centavos: the
     * period's `principal_due` as it stood that day, less the principal of
     * posted payments dated before it.
     *
     * - The principal that day comes from its history
     *   (AmortizationSchedule::principalChanges()): the latest change dated
     *   before the day; before the first change, that change's principal
     *   before it; with no change, the current `principal_due`.
     * - Anything recorded on a charge's own day (a payment, an adjustment)
     *   comes after that day's charge, as a payment's own penalty step runs
     *   before its allocation, and the night's run before the day's work.
     * - Principal paid with no allocation row (an imported loan's history)
     *   counts as paid before every charge.
     *
     * - `$asCharged`: a voided payment also counts as paid on the days after
     *   its date up to the day it was voided (penaltyCharges()).
     *
     * A list eager-loads both histories; otherwise they are read here.
     *
     * @return Closure(Carbon): int
     */
    private function principalUnpaidOn(AmortizationSchedule $schedule, bool $asCharged = false): Closure
    {
        $dueNow = LoanService::toCentavos((float) $schedule->principal_due);
        $paid = LoanService::toCentavos((float) $schedule->principal_paid);

        $changes = ($schedule->relationLoaded('principalChanges')
            ? $schedule->principalChanges
            : $schedule->principalChanges()->get())
            ->map(fn (AmortizationPrincipalChange $change): array => [
                'on' => $change->effective_on->copy()->startOfDay(),
                'before' => LoanService::toCentavos((float) $change->principal_due_before),
                'after' => LoanService::toCentavos((float) $change->principal_due_after),
            ])
            ->values();

        $payments = $paid <= 0 ? collect() : ($schedule->relationLoaded('postedPrincipalPayments')
            ? $schedule->postedPrincipalPayments
            : $schedule->postedPrincipalPayments()->get())
            ->map(fn (RepaymentAllocation $row): array => [
                'on' => Carbon::parse($row->payment_date)->startOfDay(),
                'principal' => LoanService::toCentavos((float) $row->principal),
            ]);

        $unallocated = max(0, $paid - $payments->sum('principal'));

        if ($asCharged) {
            $payments = $payments->concat(($schedule->relationLoaded('voidedPrincipalPayments')
                ? $schedule->voidedPrincipalPayments
                : $schedule->voidedPrincipalPayments()->get())
                ->map(fn (RepaymentAllocation $row): array => [
                    'on' => Carbon::parse($row->payment_date)->startOfDay(),
                    'principal' => LoanService::toCentavos((float) $row->principal),
                    'until' => Carbon::parse($row->voided_at)->startOfDay(),
                ]));
        }

        $dueOn = function (Carbon $on) use ($changes, $dueNow): int {
            if ($changes->isEmpty()) {
                return $dueNow;
            }

            $latest = $changes->filter(fn (array $change): bool => $change['on']->lt($on))->last();

            return $latest === null ? $changes->first()['before'] : $latest['after'];
        };

        return function (Carbon $on) use ($dueOn, $payments, $unallocated): int {
            $due = $dueOn($on);
            $paidBefore = $unallocated + $payments
                ->filter(fn (array $payment): bool => $payment['on']->lt($on)
                    && (! isset($payment['until']) || $on->lte($payment['until'])))
                ->sum('principal');

            return max(0, $due - $paidBefore);
        };
    }

    /**
     * Full calendar months from `$from` to `$to`, a month landing on the same
     * day of the month, capped at month end (31 Jan to 28 Feb is one).
     */
    private static function fullMonthsBetween(Carbon $from, Carbon $to): int
    {
        $months = ($to->year - $from->year) * 12 + ($to->month - $from->month);

        while ($months > 0 && $from->copy()->addMonthsNoOverflow($months)->gt($to)) {
            $months--;
        }

        return max(0, $months);
    }

    /**
     * Allocate remaining cash to a single schedule: penalty → interest → principal.
     *
     * In whole centavos: each part takes min(what it still owes, what is left),
     * and its `*_paid` is written back exactly, so a period paid in full owes
     * exactly nothing and the money left is exactly what the period did not
     * take.
     *
     * @return array{0: int, 1: int, 2: int, 3: int} [remaining, principalPaid, interestPaid, penaltyPaid], in centavos
     */
    private function allocateToSchedule(AmortizationSchedule $schedule, int $remaining): array
    {
        $centavos = fn (mixed $pesos): int => LoanService::toCentavos((float) ($pesos ?? 0));

        $penaltyDue = $centavos($schedule->penalty_amount);
        $interestDue = $centavos($schedule->interest_due);
        $principalDue = $centavos($schedule->principal_due);
        $penaltyPaidBefore = $centavos($schedule->penalty_paid);
        $interestPaidBefore = $centavos($schedule->interest_paid);
        $principalPaidBefore = $centavos($schedule->principal_paid);

        $penaltyPaid = min(max(0, $penaltyDue - $penaltyPaidBefore), max(0, $remaining));
        $remaining -= $penaltyPaid;

        $interestPaid = min(max(0, $interestDue - $interestPaidBefore), max(0, $remaining));
        $remaining -= $interestPaid;

        $principalPaid = min(max(0, $principalDue - $principalPaidBefore), max(0, $remaining));
        $remaining -= $principalPaid;

        if ($penaltyPaid > 0) {
            $schedule->penalty_paid = ($penaltyPaidBefore + $penaltyPaid) / 100;
        }

        if ($interestPaid > 0) {
            $schedule->interest_paid = ($interestPaidBefore + $interestPaid) / 100;
        }

        if ($principalPaid > 0) {
            $schedule->principal_paid = ($principalPaidBefore + $principalPaid) / 100;
        }

        // Determine schedule status
        $principalFullyPaid = $principalPaidBefore + $principalPaid >= $principalDue;
        $interestFullyPaid = $interestPaidBefore + $interestPaid >= $interestDue;
        $penaltyFullyPaid = $penaltyDue === 0 || $penaltyPaidBefore + $penaltyPaid >= $penaltyDue;

        if ($principalFullyPaid && $interestFullyPaid && $penaltyFullyPaid) {
            $schedule->status = 'paid';
        } elseif ($principalPaidBefore + $principalPaid > 0 || $interestPaidBefore + $interestPaid > 0) {
            $schedule->status = 'partial';
        }

        $schedule->save();

        return [$remaining, $principalPaid, $interestPaid, $penaltyPaid];
    }

    /**
     * Refuse a void that cannot say which periods the payment paid.
     *
     * A payment recorded before `repayment_allocations` existed has rows only
     * where the backfill (2026_10_01_150200) could prove them, so one without
     * rows that still put money on periods is a guess away from reversing the
     * wrong ones. A row whose period is gone means a restructure, a term
     * extension or an extension has since replaced the period it paid.
     * Either way the void stops here, before its transaction opens.
     *
     * @throws ValidationException on `repayment`
     */
    private function assertAllocationIsKnown(Repayment $repayment): void
    {
        $applied = (float) $repayment->principal_applied
            + (float) $repayment->interest_applied
            + (float) $repayment->penalty_applied;

        if ($repayment->allocations->isEmpty() && $applied > 0) {
            throw ValidationException::withMessages([
                'repayment' => 'This payment was recorded before Lendyph kept track of which periods each payment paid, '
                    .'and they cannot be worked out from this loan\'s history, so it cannot be voided. '
                    .'Use a balance adjustment to correct the loan instead.',
            ]);
        }

        if ($repayment->allocations->contains(fn (RepaymentAllocation $allocation) => $allocation->amortization_schedule_id === null)) {
            throw ValidationException::withMessages([
                'repayment' => 'A period this payment paid has since been replaced by a restructure or an extension, '
                    .'so it cannot be voided. Use a balance adjustment to correct the loan instead.',
            ]);
        }
    }

    /**
     * Refuse a void of the payment an extension collected with.
     *
     * The extension closed the period that payment paid, at what it paid, and
     * carried the rest forward; voiding it would refund the money, re-open the
     * closed period as overdue and charge its penalty again, and a closed
     * period is never recalculated. When the payment reached interest, the
     * extension's ledger credit records it too, and a void takes nothing out
     * of the loan ledger.
     *
     * @throws ValidationException on `repayment`
     */
    private function assertNotAnExtensionCollection(Repayment $repayment): void
    {
        $credited = LoanLedgerEntry::where('repayment_id', $repayment->id)
            ->where('type', 'credit')
            ->whereNotNull('loan_adjustment_id')
            ->with('loanAdjustment:id,adjustment_number')
            ->first()
            ?->loanAdjustment;

        if ($credited) {
            throw ValidationException::withMessages([
                'repayment' => "This payment collected the interest when the loan was extended ({$credited->adjustment_number}), "
                    .'and that extension\'s ledger credit records it, so it cannot be voided. '
                    .'Use a balance adjustment to correct the loan instead.',
            ]);
        }

        // The link the extension records (`new_values.repayment_id`), which
        // holds whatever the collection went to: one taken wholly by penalty,
        // or only by the premium, writes no interest credit.
        $linked = LoanAdjustment::where('loan_id', $repayment->loan_id)
            ->where('adjustment_type', 'extension')
            ->where('new_values->repayment_id', $repayment->id)
            ->first(['id', 'adjustment_number']);

        if ($linked) {
            throw ValidationException::withMessages([
                'repayment' => "This payment was collected when the loan was extended ({$linked->adjustment_number}), "
                    .'and the extension closed the period it paid, so it cannot be voided. '
                    .'Use a balance adjustment to correct the loan instead.',
            ]);
        }
    }

    /**
     * Take back exactly what this payment put on each period, from its
     * allocation rows. Periods it did not reach are not touched.
     *
     * Each period it reopens is charged its late penalty as of today, the day
     * of the void, the same figure a payment today and the nightly run work
     * out: the months since the payment are owed too, and with the voided
     * principal unpaid again, the months after the payment go back up.
     * Winding the charge back to the payment's own date left the stored
     * penalty below what the loan owed until the next run.
     *
     * `$penaltyFrozen` (a defaulted loan, or one the void returns to
     * defaulted) leaves each period's charge as it stands instead.
     */
    private function reverseAllocation(Repayment $repayment, bool $penaltyFrozen = false): void
    {
        $loan = $repayment->loan;
        $today = Carbon::today();

        $schedules = AmortizationSchedule::whereIn('id', $repayment->allocations->pluck('amortization_schedule_id'))
            ->get()
            ->keyBy('id');

        // Same definition of late as applyPenalties() a hundred lines up. The
        // status re-derivation below is the other place this service stamps
        // `overdue`, so voiding a repayment against a schedule still inside its
        // grace window would otherwise put back exactly the label the penalty
        // fix removes.
        //
        // The imported-arrears baseline rides along for the same reason, and it
        // is the reason a void cannot be treated as a purely arithmetic
        // reversal: applyPenalties() never stamped a pre-import schedule
        // `overdue`, so re-deriving the status from the date alone would put
        // that label back on a schedule the exclusion exists to keep clean —
        // and the loans screen would start showing a migrated member as newly
        // delinquent because someone voided a mistyped receipt.
        $lateBefore = AmortizationSchedule::pastGraceCutoff($loan->grace_period_days, $today);
        $arrearsBaseline = $loan->imported_arrears_baseline;

        foreach ($repayment->allocations as $allocation) {
            $schedule = $schedules[$allocation->amortization_schedule_id];

            $schedule->penalty_paid = max(0, round((float) $schedule->penalty_paid - (float) $allocation->penalty, 2));
            $schedule->interest_paid = max(0, round((float) $schedule->interest_paid - (float) $allocation->interest, 2));
            $schedule->principal_paid = max(0, round((float) $schedule->principal_paid - (float) $allocation->principal, 2));

            // The payment being voided is still posted inside this
            // transaction; the penalty is worked as if it never was.
            $schedule->setRelation('postedPrincipalPayments', $schedule->postedPrincipalPayments()
                ->where('repayment_allocations.repayment_id', '!=', $repayment->id)
                ->get());

            // Recalculate schedule status
            $fullyUnpaid = $schedule->principal_paid == 0 && $schedule->interest_paid == 0 && $schedule->penalty_paid == 0;

            if ($fullyUnpaid) {
                $isLate = $schedule->due_date->lt($lateBefore)
                    && AmortizationSchedule::isPenalisable($arrearsBaseline, $schedule->due_date);

                $schedule->status = $isLate ? 'overdue' : 'pending';
            } else {
                $schedule->status = 'partial';
            }

            // A late period owes the penalty applyPenalties() charges it,
            // from the same method. Setting 0 and waiting for the next payment
            // or the nightly run to recharge it left the period overdue with no
            // penalty, and the loan's total payable short by that charge, in
            // between. A fully unpaid period that is not late owes none, and
            // nor does any period of a loan with no penalty rate.
            //
            // Except on a period a waiver from before the switch made
            // penalty-free, which applyPenalties() never charges again. There
            // the charge the waiver kept stands, owed again now that the
            // payment that covered it is void. A waiver made since keeps that
            // charge through its checkpoint (penaltyStateAsOf()).
            //
            // And except on a loan whose penalty is frozen (penaltyIsFrozen()),
            // or one this void returns to defaulted: the charge stands as it
            // was, so reopening a period charges no missed months.
            if (! $schedule->isWaivedForGood() && ! $penaltyFrozen) {
                $state = $this->penaltyStateAsOf($loan, $schedule, $today);

                if ($state !== null) {
                    $schedule->penalty_amount = $state['amount'];
                    $schedule->penalty_months = $state['months'];
                } elseif ($fullyUnpaid) {
                    $schedule->penalty_amount = 0;
                    $schedule->penalty_months = 0;
                }
            }

            $schedule->save();
        }
    }

    /**
     * Whether a payment's overpayment on this loan becomes a share capital
     * build-up credit: the loan carries a build-up (`scb_amount`, fixed once
     * the loan is released) and the member isn't a pending or rejected
     * applicant, who can't hold share capital (the same gate as
     * StoreShareCapitalLedgerRequest::memberBorrowerRule()). Recording a
     * payment credits by this rule and its preview runs that same code.
     */
    public static function creditsBuildUp(Loan $loan): bool
    {
        return (float) $loan->scb_amount > 0
            && ! in_array($loan->borrower->status, Borrower::NON_MEMBER_STATUSES, true);
    }
}
