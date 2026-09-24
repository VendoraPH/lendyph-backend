<?php

namespace App\Services;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\Repayment;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use App\Services\Accounting\AutomaticPoster;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RepaymentService
{
    /**
     * Record a repayment and allocate it across schedules.
     */
    public function processRepayment(
        Loan $loan,
        float $amountPaid,
        string $paymentDate,
        User $user,
        ?string $remarks = null,
        string $method = 'cash',
        ?string $referenceNumber = null,
        bool $postToBooks = true,
    ): Repayment {
        if (! in_array($loan->status, ['released', 'ongoing'])) {
            throw ValidationException::withMessages([
                'loan' => 'Repayments can only be recorded for released or ongoing loans.',
            ]);
        }

        $paymentDate = Carbon::parse($paymentDate);

        return DB::transaction(function () use ($loan, $amountPaid, $paymentDate, $user, $remarks, $method, $referenceNumber, $postToBooks) {
            // Step 1: Compute penalties on overdue schedules
            $this->applyPenalties($loan, $paymentDate);

            // Capture outstanding principal balance before payment
            $balanceBefore = (float) $loan->amortizationSchedules()
                ->whereIn('status', ['pending', 'partial', 'overdue'])
                ->sum(DB::raw('principal_due - principal_paid'));

            // Step 2: Get all unpaid/partial schedules ordered by period
            $schedules = $loan->amortizationSchedules()
                ->whereIn('status', ['pending', 'partial', 'overdue'])
                ->orderBy('period_number')
                ->get();

            // Identify "current" schedule = first not-overdue schedule (due_date >= paymentDate)
            // If all unpaid are overdue, the current = the most recent overdue (largest period_number among overdue)
            $currentScheduleId = null;
            $firstNotOverdue = $schedules->first(fn ($s) => $s->due_date->gte($paymentDate));
            if ($firstNotOverdue) {
                $currentScheduleId = $firstNotOverdue->id;
            } else {
                $currentScheduleId = $schedules->last()?->id;
            }

            $remaining = $amountPaid;
            $principalApplied = 0.0;
            $interestApplied = 0.0;
            $penaltyApplied = 0.0;
            $overdueInterestApplied = 0.0;
            $currentInterestApplied = 0.0;
            $currentPrincipalApplied = 0.0;
            $nextInterestApplied = 0.0;
            $nextPrincipalApplied = 0.0;
            $touchedFutureSchedule = false;

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

                if ($isFuture && ! $hasScb) {
                    $touchedFutureSchedule = true;
                }

                // Allocate: penalty → interest → principal
                [$remaining, $pPaid, $iPaid, $penPaid] = $this->allocateToSchedule(
                    $schedule,
                    $remaining,
                );

                $principalApplied += $pPaid;
                $interestApplied += $iPaid;
                $penaltyApplied += $penPaid;

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

            if ($touchedFutureSchedule) {
                $paymentType = 'advance';
            } elseif ($allDueSchedules === 0 && $overpayment == 0) {
                $paymentType = 'exact';
            } else {
                $paymentType = 'partial';
            }

            // Step 5: Persist repayment
            $balanceAfter = max(0, $balanceBefore - round($principalApplied, 2));

            $repayment = Repayment::create([
                'loan_id' => $loan->id,
                'payment_date' => $paymentDate,
                'method' => $method,
                'reference_number' => $referenceNumber,
                'amount_paid' => $amountPaid,
                'principal_applied' => round($principalApplied, 2),
                'interest_applied' => round($interestApplied, 2),
                'penalty_applied' => round($penaltyApplied, 2),
                'overdue_interest_applied' => round($overdueInterestApplied, 2),
                'current_interest_applied' => round($currentInterestApplied, 2),
                'current_principal_applied' => round($currentPrincipalApplied, 2),
                'next_interest_applied' => round($nextInterestApplied, 2),
                'next_principal_applied' => round($nextPrincipalApplied, 2),
                'overpayment' => round($overpayment, 2),
                'balance_before' => round($balanceBefore, 2),
                'balance_after' => round($balanceAfter, 2),
                'payment_type' => $paymentType,
                'status' => 'posted',
                'received_by' => $user->id,
                'remarks' => $remarks,
            ]);

            // Step 6: Loan status lifecycle
            // released → ongoing on first payment, → completed when all schedules paid
            $unpaidCount = $loan->amortizationSchedules()
                ->whereIn('status', ['pending', 'partial', 'overdue'])
                ->count();

            if ($unpaidCount === 0) {
                $loan->update(['status' => 'completed']);
            } elseif ($loan->status === 'released') {
                // No CollateralPledgeGuard call here on purpose. `released` and
                // `ongoing` are BOTH in Loan::ACTIVE_STATUSES, so this loan is
                // already an active holder of whatever it holds and this write
                // cannot add one. The guard is for transitions INTO the active
                // set from outside it; see voidRepayment() below.
                $loan->update(['status' => 'ongoing']);
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
            // Rounded BEFORE the `> 0` check, not after: gating on the raw
            // float would let sub-centavo residue from the allocation loop's
            // arithmetic (e.g. 1.0E-13) through as "an overpayment", writing a
            // credited $0.00 row that reverseShareCapitalCredit()'s `credit >
            // 0` lookup would then never find to clean up on a later void.
            $scbCredit = round($overpayment, 2);

            if ($hasScb && $scbCredit > 0 && ! in_array($loan->borrower->status, Borrower::NON_MEMBER_STATUSES, true)) {
                ShareCapitalLedger::create([
                    'borrower_id' => $loan->borrower_id,
                    'repayment_id' => $repayment->id,
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
        });
    }

    /**
     * Compute how a hypothetical repayment would be allocated without persisting anything.
     *
     * Uses a real-but-rolled-back database transaction so the preview matches the actual
     * processRepayment logic exactly (penalty computation, schedule allocation, status flip).
     * Nothing is saved because the outer DB::beginTransaction() wraps the inner transaction
     * inside processRepayment and rolls back everything, including the Repayment row itself.
     */
    public function previewAllocation(
        Loan $loan,
        float $amountPaid,
        string $paymentDate,
        User $user,
    ): array {
        if (! in_array($loan->status, ['released', 'ongoing'])) {
            throw ValidationException::withMessages([
                'loan' => 'Repayment preview is only available for released or ongoing loans.',
            ]);
        }

        DB::beginTransaction();

        try {
            $repayment = $this->processRepayment($loan, $amountPaid, $paymentDate, $user, postToBooks: false);

            return [
                'amount_paid' => (float) $repayment->amount_paid,
                // Frontend-canonical scalar totals (consumed by /payments entry page)
                'total_paid' => (float) $repayment->amount_paid,
                'total_principal' => (float) $repayment->principal_applied,
                'total_interest' => (float) $repayment->interest_applied,
                'total_penalty' => (float) $repayment->penalty_applied,
                'excess' => (float) $repayment->overpayment,
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
                'balance_before' => (float) $repayment->balance_before,
                'balance_after' => (float) $repayment->balance_after,
                'payment_type' => $repayment->payment_type,
                'is_preview' => true,
            ];
        } finally {
            DB::rollBack();
        }
    }

    /**
     * Void a posted repayment and reverse its effects.
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
        // than incidental.
        $repayment->loadMissing('loan');

        return DB::transaction(function () use ($repayment, $reason, $user) {
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

            // Reverse allocation from schedules — we need to know per-schedule amounts.
            // We stored totals only, so we reverse using a proportional approach:
            // Re-run the allocation simulation and reverse each schedule.
            $this->reverseAllocation($repayment);

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
                // Loan::ACTIVE_STATUSES, and it writes no `loan_collaterals`
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

                $loan->update(['status' => $remainingPayments > 0 ? 'ongoing' : 'released']);
            }

            return $repayment;
        });
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

        $schedules = $loan->amortizationSchedules()->get();

        $totalDue = $schedules->sum('total_due');
        $totalPrincipalPaid = $schedules->sum('principal_paid');
        $totalInterestPaid = $schedules->sum('interest_paid');
        $totalPenaltyPaid = $schedules->sum('penalty_paid');
        $totalPenaltyDue = $schedules->sum('penalty_amount');
        $totalPaid = $totalPrincipalPaid + $totalInterestPaid + $totalPenaltyPaid;

        $outstandingPrincipal = $schedules->sum(fn ($s) => (float) $s->principal_due - (float) $s->principal_paid);
        $outstandingInterest = $schedules->sum(fn ($s) => max(0, (float) $s->interest_due - (float) $s->interest_paid));
        $outstandingPenalty = $schedules->sum(fn ($s) => max(0, (float) $s->penalty_amount - (float) $s->penalty_paid));

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
            'outstanding_principal' => round($outstandingPrincipal, 2),
            'outstanding_interest' => round($outstandingInterest, 2),
            'outstanding_penalty' => round($outstandingPenalty, 2),
            // Frontend reads `penalty_amount` meaning the remaining penalty to collect.
            'penalty_amount' => round($outstandingPenalty, 2),
            'outstanding_balance' => round($outstandingPrincipal + $outstandingInterest + $outstandingPenalty, 2),
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
     */
    public function applyPenalties(Loan $loan, Carbon $asOfDate): void
    {
        $penaltyRate = (float) $loan->penalty_rate;

        if ($penaltyRate <= 0) {
            return;
        }

        $lateBefore = AmortizationSchedule::pastGraceCutoff($loan->grace_period_days, $asOfDate);
        $arrearsBaseline = $loan->imported_arrears_baseline;

        $loan->amortizationSchedules()
            ->where('due_date', '<', $lateBefore)
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->each(function (AmortizationSchedule $schedule) use ($penaltyRate, $arrearsBaseline) {
                if (! AmortizationSchedule::isPenalisable($arrearsBaseline, $schedule->due_date)) {
                    return;
                }

                $remainingDue = (float) $schedule->principal_due - (float) $schedule->principal_paid;
                $penalty = round($remainingDue * ($penaltyRate / 100), 2);

                $schedule->update([
                    'penalty_amount' => $penalty,
                    'status' => 'overdue',
                ]);
            });
    }

    /**
     * Allocate remaining cash to a single schedule: penalty → interest → principal.
     * Returns [remaining, principalPaid, interestPaid, penaltyPaid].
     */
    private function allocateToSchedule(AmortizationSchedule $schedule, float $remaining): array
    {
        $principalPaid = 0.0;
        $interestPaid = 0.0;
        $penaltyPaid = 0.0;

        // Pay penalty first
        $penaltyOwed = max(0, (float) $schedule->penalty_amount - (float) $schedule->penalty_paid);
        if ($penaltyOwed > 0 && $remaining > 0) {
            $penPay = min($penaltyOwed, $remaining);
            $schedule->penalty_paid = round((float) $schedule->penalty_paid + $penPay, 2);
            $penaltyPaid += $penPay;
            $remaining -= $penPay;
        }

        // Pay interest
        $interestOwed = max(0, (float) $schedule->interest_due - (float) $schedule->interest_paid);
        if ($interestOwed > 0 && $remaining > 0) {
            $iPay = min($interestOwed, $remaining);
            $schedule->interest_paid = round((float) $schedule->interest_paid + $iPay, 2);
            $interestPaid += $iPay;
            $remaining -= $iPay;
        }

        // Pay principal
        $principalOwed = max(0, (float) $schedule->principal_due - (float) $schedule->principal_paid);
        if ($principalOwed > 0 && $remaining > 0) {
            $pPay = min($principalOwed, $remaining);
            $schedule->principal_paid = round((float) $schedule->principal_paid + $pPay, 2);
            $principalPaid += $pPay;
            $remaining -= $pPay;
        }

        // Determine schedule status
        $principalFullyPaid = (float) $schedule->principal_paid >= (float) $schedule->principal_due;
        $interestFullyPaid = (float) $schedule->interest_paid >= (float) $schedule->interest_due;
        $penaltyFullyPaid = $schedule->penalty_amount == 0 || (float) $schedule->penalty_paid >= (float) $schedule->penalty_amount;

        if ($principalFullyPaid && $interestFullyPaid && $penaltyFullyPaid) {
            $schedule->status = 'paid';
        } elseif ($schedule->principal_paid > 0 || $schedule->interest_paid > 0) {
            $schedule->status = 'partial';
        }

        $schedule->save();

        return [$remaining, $principalPaid, $interestPaid, $penaltyPaid];
    }

    /**
     * Reverse the payment allocation for a voided repayment by re-simulating allocation
     * and subtracting from each schedule.
     */
    private function reverseAllocation(Repayment $repayment): void
    {
        $loan = $repayment->loan;
        $amountPaid = (float) $repayment->amount_paid;
        $paymentDate = $repayment->payment_date;

        // Replay allocation simulation to know what went where, then subtract
        $schedules = $loan->amortizationSchedules()
            ->orderBy('period_number')
            ->get();

        $remaining = $amountPaid;

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
        $lateBefore = AmortizationSchedule::pastGraceCutoff($loan->grace_period_days, $paymentDate);
        $arrearsBaseline = $loan->imported_arrears_baseline;

        foreach ($schedules as $schedule) {
            if ($remaining <= 0) {
                break;
            }

            // Simulate how much penalty/interest/principal was taken from this schedule
            // We reverse by computing how much was paid (capped by what was actually allocated)

            // Penalty reverse
            $penaltyOwed = min((float) $schedule->penalty_paid, $remaining);
            if ($penaltyOwed > 0) {
                $schedule->penalty_paid = max(0, round((float) $schedule->penalty_paid - $penaltyOwed, 2));
                $remaining -= $penaltyOwed;
            }

            // Interest reverse
            $interestOwed = min((float) $schedule->interest_paid, $remaining);
            if ($interestOwed > 0) {
                $schedule->interest_paid = max(0, round((float) $schedule->interest_paid - $interestOwed, 2));
                $remaining -= $interestOwed;
            }

            // Principal reverse
            $principalOwed = min((float) $schedule->principal_paid, $remaining);
            if ($principalOwed > 0) {
                $schedule->principal_paid = max(0, round((float) $schedule->principal_paid - $principalOwed, 2));
                $remaining -= $principalOwed;
            }

            // Recalculate schedule status
            if ($schedule->principal_paid == 0 && $schedule->interest_paid == 0 && $schedule->penalty_paid == 0) {
                $isLate = $schedule->due_date->lt($lateBefore)
                    && AmortizationSchedule::isPenalisable($arrearsBaseline, $schedule->due_date);

                $schedule->status = $isLate ? 'overdue' : 'pending';
                $schedule->penalty_amount = 0;
            } else {
                $schedule->status = 'partial';
            }

            $schedule->save();
        }
    }
}
