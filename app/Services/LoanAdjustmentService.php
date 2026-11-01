<?php

namespace App\Services;

use App\Enums\TermUnit;
use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\LoanLedgerEntry;
use App\Models\Repayment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class LoanAdjustmentService
{
    public function __construct(
        private LoanService $loanService,
        private RepaymentService $repaymentService,
    ) {}

    public function createAdjustment(Loan $loan, array $validated, User $user): LoanAdjustment
    {
        if (! in_array($loan->status, ['released', 'ongoing'])) {
            throw ValidationException::withMessages([
                'loan' => 'Adjustments can only be made on released or ongoing loans.',
            ]);
        }

        $reschedules = in_array($validated['adjustment_type'], ['restructure', 'term_extension'], true);
        $newFrequency = $validated['new_values']['frequency'] ?? $loan->frequency;

        if ($reschedules && $newFrequency === 'upon_maturity'
            && ! LoanTermSchedule::stepsByCalendarMonth($loan->term_unit->value, $newFrequency)) {
            throw ValidationException::withMessages([
                'adjustment_type' => 'A single-payment loan with a days term has no instalments to reschedule. Restructure it into a new loan instead.',
            ]);
        }

        // Stated as a target: the server works the amount out, and records
        // what it worked it out from beside it.
        if ($validated['adjustment_type'] === 'balance_adjustment'
            && ($validated['new_values']['target_principal_balance'] ?? null) !== null) {
            $figures = $this->balanceAdjustmentFigures($loan, (float) $validated['new_values']['target_principal_balance']);
            $validated['new_values']['adjustment_amount'] = $figures['adjustment_amount'];
            $validated['new_values']['target_principal_balance'] = $figures['target_principal_balance'];
            $validated['new_values']['outstanding_principal_at_request'] = $figures['outstanding_principal'];
        }

        $oldValues = $this->captureOldValues($loan, $validated['adjustment_type'], $validated['new_values']);

        return LoanAdjustment::create([
            'loan_id' => $loan->id,
            'adjustment_type' => $validated['adjustment_type'],
            'description' => $validated['description'] ?? null,
            'old_values' => $oldValues,
            'new_values' => $validated['new_values'],
            'status' => 'pending',
            'remarks' => $validated['remarks'] ?? null,
            'adjusted_by' => $user->id,
        ]);
    }

    /**
     * What a balance adjustment to `$targetPrincipalBalance` would be, for
     * GET /loans/{loan}/balance-adjustment-preview. Refuses, as a request
     * would, a loan that cannot be adjusted. Writes nothing.
     *
     * @return array{outstanding_principal: float|int, target_principal_balance: float|int, adjustment_amount: float|int, resulting_principal_balance: float|int}
     */
    public function balanceAdjustmentPreview(Loan $loan, float $targetPrincipalBalance): array
    {
        if (! in_array($loan->status, ['released', 'ongoing'])) {
            throw ValidationException::withMessages([
                'loan' => 'Adjustments can only be made on released or ongoing loans.',
            ]);
        }

        return $this->balanceAdjustmentFigures($loan, $targetPrincipalBalance);
    }

    /**
     * The amount that takes the loan's unpaid principal to the target, in
     * centavos: target minus outstanding principal, negative for a reduction.
     *
     * @return array{outstanding_principal: float|int, target_principal_balance: float|int, adjustment_amount: float|int, resulting_principal_balance: float|int}
     */
    private function balanceAdjustmentFigures(Loan $loan, float $targetPrincipalBalance): array
    {
        $outstanding = $this->outstandingPrincipalCentavos($loan);
        $target = (int) round($targetPrincipalBalance * 100);

        return [
            'outstanding_principal' => $outstanding / 100,
            'target_principal_balance' => $target / 100,
            'adjustment_amount' => ($target - $outstanding) / 100,
            'resulting_principal_balance' => $target / 100,
        ];
    }

    /**
     * The loan's unpaid principal, in centavos: over its open periods, each
     * period's principal due less what was paid on it, never below zero. The
     * one figure a balance adjustment is worked out from, guarded against
     * (applyBalanceAdjustment()) and run down from
     * (restateOpenRemainingBalances()).
     */
    public function outstandingPrincipalCentavos(Loan $loan): int
    {
        return $loan->amortizationSchedules()
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->get()
            ->sum(fn (AmortizationSchedule $schedule): int => self::unpaidPrincipalCentavos($schedule));
    }

    /**
     * A balance adjustment requested as a target principal balance stores the
     * amount worked out at request time, so it lands on the target only while
     * the loan's unpaid principal is still what it was then. Checked under the
     * loan's lock at approval and at apply; a moved principal is a 409 telling
     * staff to reject it and request the target again (it is not rejected for
     * them). One requested by amount is not checked.
     *
     * @throws HttpResponseException 409 when the principal moved since the request
     */
    private function assertTargetPrincipalUnchanged(LoanAdjustment $adjustment, Loan $loan): void
    {
        $own = $adjustment->ownNewValues();

        if ($adjustment->adjustment_type !== 'balance_adjustment'
            || ($own['target_principal_balance'] ?? null) === null) {
            return;
        }

        $atRequest = (int) round((float) ($own['outstanding_principal_at_request'] ?? -1) * 100);

        if ($this->outstandingPrincipalCentavos($loan) !== $atRequest) {
            throw LoanWriteTransaction::conflict("The loan's principal changed since this adjustment was requested. Reject this request and create a new one for the same target.");
        }
    }

    /**
     * One period's unpaid principal, in centavos, never below zero.
     */
    private static function unpaidPrincipalCentavos(AmortizationSchedule $schedule): int
    {
        return max(0, (int) round((float) $schedule->principal_due * 100) - (int) round((float) $schedule->principal_paid * 100));
    }

    /**
     * Approve a pending adjustment.
     *
     * The checks before the transaction refuse, with a 422, an approval that
     * was wrong from the start. The transaction then takes the lock an apply
     * takes, the loan and then this adjustment (lockAdjustmentRows()), and
     * re-reads the adjustment under it: one rejected, approved or applied by
     * another request since this one read it is a 409 and is left exactly as
     * that request left it, as is a deadlock or a lock wait timeout.
     *
     * This is the outermost transaction: LoanAdjustmentController::approve()
     * and the test helpers are the only callers.
     */
    public function approveAdjustment(LoanAdjustment $adjustment, User $approver, ?string $remarks): LoanAdjustment
    {
        $this->guardStatus($adjustment, 'pending', 'approve');
        $this->assertPayloadFitsType($adjustment);

        return LoanWriteTransaction::run(function () use ($adjustment, $approver, $remarks): LoanAdjustment {
            $loan = $this->lockAdjustmentRows($adjustment, 'pending');
            $this->assertTargetPrincipalUnchanged($adjustment, $loan);

            $adjustment->update([
                'status' => 'approved',
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'remarks' => $remarks ?? $adjustment->remarks,
            ]);

            return $adjustment;
        });
    }

    /**
     * Reject a pending adjustment, under the same lock and re-read as
     * approveAdjustment(): a reject that read the adjustment as pending, when
     * it has since been approved (and perhaps applied), is a 409 and never
     * turns an applied adjustment into a rejected one.
     *
     * This is the outermost transaction: LoanAdjustmentController::reject()
     * and the test helpers are the only callers.
     */
    public function rejectAdjustment(LoanAdjustment $adjustment, User $approver, ?string $remarks): LoanAdjustment
    {
        $this->guardStatus($adjustment, 'pending', 'reject');

        return LoanWriteTransaction::run(function () use ($adjustment, $approver, $remarks): LoanAdjustment {
            $this->lockAdjustmentRows($adjustment, 'pending');

            $adjustment->update([
                'status' => 'rejected',
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'remarks' => $remarks ?? $adjustment->remarks,
            ]);

            return $adjustment;
        });
    }

    /**
     * Roll an upon-maturity loan forward by one frequency cycle.
     *
     * Carries unpaid principal + unpaid interest from the open amortization
     * schedule(s) into a fresh bullet period, accrues new interest using the
     * loan's existing rate, and records the action as a directly-applied
     * LoanAdjustment row (no pending → approved → applied workflow).
     */
    /**
     * Roll an upon-maturity loan forward by one cycle.
     *
     * `$interestOption` decides what happens to interest already outstanding:
     *
     *   'pay'   — collect it as a repayment first, so the new period carries
     *             only the freshly accrued interest.
     *   'defer' — leave it unpaid; it carries into the new period and stacks
     *             on top of the fresh interest (₱50 outstanding + ₱50 fresh
     *             becomes ₱50 + ₱50 = ₱100 due).
     *
     * The collection happens inside this method's transaction rather than as a
     * separate call from the client. Previously the UI posted the repayment and
     * then called extend; if the extend failed the payment was already
     * committed, and the client-side guard against re-charging was a useRef
     * that reset whenever the dialog was reopened or the page refreshed. Doing
     * both here means either the borrower is charged AND the loan extends, or
     * neither happens.
     */
    public function extendLoan(
        Loan $loan,
        ?string $remarks,
        User $user,
        string $interestOption = 'pay',
    ): LoanAdjustment {
        $this->assertExtendable($loan);

        // A deadlock or lock wait timeout is a 409, not a 500. This is the
        // outermost transaction: LoanController::extend() and the test helpers
        // are the only callers. The interest is collected inside it, under its
        // loan lock, through recordRepaymentOnLockedLoan(): processRepayment()
        // opens a LoanWriteTransaction of its own, which must be the outermost.
        return LoanWriteTransaction::run(function () use ($loan, $user, $remarks, $interestOption) {
            // The loan row first, the order every loan write takes
            // (LoanWriteTransaction). No collateral lock: an extension writes
            // `ongoing` at most, over `released`, and both already pledge.
            $this->lockLoanForExtension($loan);

            $oldMaturityDate = $loan->maturity_date->toDateString();
            $oldTerm = $loan->term;

            $openBefore = $this->openSchedules($loan);
            $interestBefore = $this->outstandingInterest($openBefore);

            // Settle the accrued interest first so the new period starts clean.
            // The allocation runs penalty → interest → principal, so where a
            // penalty is outstanding this amount is consumed by that first and
            // some interest still carries. That matches what the UI did before
            // this moved server-side, and the carry figures below are re-read
            // afterwards, so the arithmetic stays correct either way.
            $interestPaid = 0.0;
            $interestRepayment = null;

            if ($interestOption === 'pay' && $interestBefore > 0) {
                $interestRepayment = $this->repaymentService->recordRepaymentOnLockedLoan(
                    $loan,
                    $interestBefore,
                    now()->toDateString(),
                    $user,
                    $remarks ?: '[EXTENSION INTEREST]',
                );

                $interestPaid = $interestBefore;
            }

            // Read after the collection: on the 'pay' path the rows above have
            // just been allocated against, so this is what genuinely remains.
            // extensionPreview() reads the same figures the same way.
            [
                'carry_principal' => $carryPrincipal,
                'carry_interest' => $carryInterest,
                'fresh_interest' => $freshInterest,
                'new_interest_due' => $newInterestDue,
                'new_total_due' => $newTotalDue,
                'new_due_date' => $newDueDate,
                'latest_open_due_date' => $latestOpenDueDate,
            ] = $this->extensionFigures($loan);

            $maxPeriodNumber = (int) $loan->amortizationSchedules()->max('period_number');

            $oldValues = [
                'maturity_date' => $oldMaturityDate,
                'term' => $oldTerm,
                'open_principal' => $carryPrincipal,
                'open_interest' => $interestBefore,
                'open_schedule_due_date' => $latestOpenDueDate->toDateString(),
            ];

            $newValues = [
                'maturity_date' => $newDueDate->toDateString(),
                // No `term` here: extending does not change the agreed term,
                // and recording one would put a change in the audit trail that
                // never happened. `old_values['term']` keeps the term as it
                // stood, which is what the drift backfill reads.
                'carry_principal' => $carryPrincipal,
                'carry_interest' => $carryInterest,
                'fresh_interest' => $freshInterest,
                'new_due_date' => $newDueDate->toDateString(),
                'interest_option' => $interestOption,
                'interest_paid' => $interestPaid,
            ];

            $adjustment = LoanAdjustment::create([
                'loan_id' => $loan->id,
                'adjustment_type' => 'extension',
                'description' => 'Loan extended by one cycle.',
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'status' => 'applied',
                'remarks' => $remarks,
                'adjusted_by' => $user->id,
                'applied_at' => now(),
            ]);

            $this->closeOpenSchedules($loan, $adjustment, $carryPrincipal);

            AmortizationSchedule::create([
                'loan_id' => $loan->id,
                'period_number' => $maxPeriodNumber + 1,
                'due_date' => $newDueDate->toDateString(),
                'principal_due' => $carryPrincipal,
                'interest_due' => $newInterestDue,
                'total_due' => $newTotalDue,
                'remaining_balance' => 0,
                'status' => 'pending',
            ]);

            $loan->update([
                // Only the maturity date moves. `term` is the term the loan was
                // agreed at and stays fixed however many cycles it rolls
                // forward — see Loan::extensionCount() for how far it has.
                'maturity_date' => $newDueDate,
            ]);

            $this->recordExtensionLedgerEntries(
                $loan,
                $adjustment,
                $freshInterest,
                $interestPaid,
                $interestRepayment,
            );

            return $adjustment;
        });
    }

    /**
     * What POST /loans/{loan}/extend with `$interestOption` would collect and
     * record, for GET /loans/{loan}/extension-preview. Writes nothing.
     *
     * Refuses, with the same 422s, a loan the extension would refuse. The rest
     * runs as extendLoan() does, under the loan's lock
     * (RepaymentService::rolledBackUnderPaymentLock()) in a transaction that is
     * then rolled back: it reads the outstanding interest under the lock, on
     * 'pay' collects it through the real allocation (penalty first), and reads
     * the carry figures after it through extensionFigures(), the method
     * extendLoan() reads them with, so the preview and the extension cannot
     * drift.
     *
     * @return array{interest_option: string, collect_now: array{amount: float, penalty_part: float, interest_part: float}, carry_principal: float, carry_interest: float, fresh_interest: float, new_interest_due: float, new_total_due: float, new_due_date: string}
     */
    public function extensionPreview(Loan $loan, string $interestOption, User $user, Carbon $asOf): array
    {
        $this->assertExtendable($loan);

        return $this->repaymentService->rolledBackUnderPaymentLock($loan, function (Loan $loan) use ($interestOption, $user, $asOf): array {
            $collectNow = ['amount' => 0.0, 'penalty_part' => 0.0, 'interest_part' => 0.0];
            $interestBefore = $this->outstandingInterest($this->openSchedules($loan));

            if ($interestOption === 'pay' && $interestBefore > 0) {
                $repayment = $this->repaymentService->recordRepaymentOnLockedLoan(
                    $loan,
                    $interestBefore,
                    $asOf->toDateString(),
                    $user,
                    postToBooks: false,
                );

                $collectNow = [
                    'amount' => round((float) $repayment->amount_paid, 2),
                    'penalty_part' => round((float) $repayment->penalty_applied, 2),
                    'interest_part' => round((float) $repayment->interest_applied, 2),
                ];
            }

            $figures = $this->extensionFigures($loan);

            return [
                'interest_option' => $interestOption,
                'collect_now' => $collectNow,
                'carry_principal' => $figures['carry_principal'],
                'carry_interest' => $figures['carry_interest'],
                'fresh_interest' => $figures['fresh_interest'],
                'new_interest_due' => $figures['new_interest_due'],
                'new_total_due' => $figures['new_total_due'],
                'new_due_date' => $figures['new_due_date']->toDateString(),
            ];
        });
    }

    /**
     * Refuse, with a 422, a loan that cannot be extended.
     *
     * Extension is limited to one-month-term loans, whatever the product.
     * Loan::isOneMonthTerm() reads the ORIGINAL term rather than the current
     * one: each extension moves the maturity date, so checking the stored
     * figures would allow exactly one extension and then lock the loan out. A
     * one-month loan can roll forward as often as needed.
     */
    private function assertExtendable(Loan $loan): void
    {
        if (! $loan->isOneMonthTerm()) {
            throw ValidationException::withMessages([
                'term' => 'Only loans with a one-month term can be extended.',
            ]);
        }

        if (! in_array($loan->status, ['released', 'ongoing'])) {
            throw ValidationException::withMessages([
                'status' => 'Only released or ongoing loans can be extended.',
            ]);
        }

        if (! $loan->amortizationSchedules()->whereIn('status', ['pending', 'partial', 'overdue'])->exists()) {
            throw ValidationException::withMessages([
                'loan' => 'Loan has no open period to extend.',
            ]);
        }
    }

    /**
     * The figures an extension records, read from the loan's open periods as
     * they stand: after the 'pay' collection has been allocated, or untouched
     * on 'defer'. The one place extendLoan() and extensionPreview() take them
     * from. Reads only.
     *
     * The fresh interest is one month's interest at the loan's rate, whatever
     * period the rate is quoted per: one flat cycle regardless of how many
     * days it spans. On 'defer' the unpaid interest is still in the carry, so
     * the fresh cycle stacks on top of it.
     *
     * @return array{carry_principal: float, carry_interest: float, fresh_interest: float, new_interest_due: float, new_total_due: float, new_due_date: Carbon, latest_open_due_date: Carbon}
     */
    private function extensionFigures(Loan $loan): array
    {
        $openSchedules = $this->openSchedules($loan);

        $carryPrincipal = round($openSchedules->sum(
            fn ($s) => (float) $s->principal_due - (float) $s->principal_paid,
        ), 2);
        $carryInterest = $this->outstandingInterest($openSchedules);

        $freshInterest = round($carryPrincipal * LoanTermSchedule::rateForDays(
            (float) $loan->interest_rate,
            $loan->interest_rate_frequency->value,
            LoanTermSchedule::DAYS_PER_MONTH,
        ), 2);

        $latestOpenDueDate = Carbon::parse($openSchedules->max('due_date'));
        $newInterestDue = round($carryInterest + $freshInterest, 2);

        return [
            'carry_principal' => $carryPrincipal,
            'carry_interest' => $carryInterest,
            'fresh_interest' => $freshInterest,
            'new_interest_due' => $newInterestDue,
            'new_total_due' => round($carryPrincipal + $newInterestDue, 2),
            'new_due_date' => $this->nextCycleDueDate($loan, $latestOpenDueDate),
            'latest_open_due_date' => $latestOpenDueDate,
        ];
    }

    /**
     * Lock the loan being extended and refuse with a 409 if it changed since
     * the request read it.
     *
     * An extension always moves `maturity_date`, and so does every other
     * rescheduling write, so a maturity date that no longer matches the one
     * this request read means another extension (or an adjustment) got there
     * first; extending again would roll the loan forward twice and collect the
     * interest twice. A loan no longer released or ongoing, or left with no
     * open period, has likewise moved under the request. `$loan` is then
     * refreshed from its locked row.
     *
     * @throws HttpResponseException 409 when the loan changed since it was read
     */
    private function lockLoanForExtension(Loan $loan): void
    {
        $locked = Loan::whereKey($loan->getKey())->lockForUpdate()->first();

        $changed = $locked === null
            || ! in_array($locked->status, ['released', 'ongoing'], true)
            || $locked->maturity_date?->toDateString() !== $loan->maturity_date?->toDateString();

        if ($changed) {
            throw LoanWriteTransaction::conflict();
        }

        $loan->setRawAttributes($locked->getAttributes(), true);

        if (! $loan->amortizationSchedules()->whereIn('status', ['pending', 'partial', 'overdue'])->exists()) {
            throw LoanWriteTransaction::conflict();
        }
    }

    /**
     * Record an extension's money movements in the loan ledger.
     *
     * The debit is the interest the extension accrues, and is always written.
     * The credit only exists on the 'pay' path, where the interest already
     * outstanding was collected first — it carries the repayment it settled so
     * the ledger and the payment list describe one event rather than two.
     *
     * Called inside extendLoan()'s transaction, so the entries commit with the
     * extension or not at all.
     */
    private function recordExtensionLedgerEntries(
        Loan $loan,
        LoanAdjustment $adjustment,
        float $freshInterest,
        float $interestPaid,
        ?Repayment $interestRepayment,
    ): void {
        $entryDate = now()->toDateString();

        if ($interestPaid > 0) {
            LoanLedgerEntry::create([
                'loan_id' => $loan->id,
                'loan_adjustment_id' => $adjustment->id,
                'repayment_id' => $interestRepayment?->id,
                'type' => 'credit',
                'category' => 'interest',
                'amount' => round($interestPaid, 2),
                'entry_date' => $entryDate,
                'description' => 'Payment of outstanding interest upon loan extension',
            ]);
        }

        LoanLedgerEntry::create([
            'loan_id' => $loan->id,
            'loan_adjustment_id' => $adjustment->id,
            'type' => 'debit',
            'category' => 'interest',
            'amount' => round($freshInterest, 2),
            'entry_date' => $entryDate,
            'description' => 'Interest charged for the extended loan term',
        ]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, AmortizationSchedule>
     */
    private function openSchedules(Loan $loan): \Illuminate\Database\Eloquent\Collection
    {
        return $loan->amortizationSchedules()
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->get();
    }

    /**
     * @param  Collection<int, AmortizationSchedule>  $schedules
     */
    private function outstandingInterest($schedules): float
    {
        return round($schedules->sum(
            fn ($s) => (float) $s->interest_due - (float) $s->interest_paid,
        ), 2);
    }

    /**
     * Close the open periods money was collected on, and delete the rest.
     *
     * A closed period is due exactly what was collected on it and is `paid`,
     * so what the borrower paid stays on the period it paid, in Total Paid,
     * and in reach of a void; only the unpaid remainder moves into the
     * rescheduled periods. Deleting partly paid periods, as this used to, took
     * collected money off the loan and left its payments unvoidable.
     *
     * `closed_by_adjustment_id` marks the period, because a closed period is
     * not paid in full and must not be the one the next restructure starts
     * after (see lastPaidInFull()). Periods are not audited models, so the
     * audit row is the only record of what each one was due before.
     */
    private function closeOpenSchedules(Loan $loan, LoanAdjustment $adjustment, float $carriedPrincipal): void
    {
        $before = [];
        $after = [];

        foreach ($this->openSchedules($loan) as $schedule) {
            $collected = round((float) $schedule->principal_paid + (float) $schedule->interest_paid + (float) $schedule->penalty_paid, 2);

            if ($collected <= 0) {
                $schedule->delete();

                continue;
            }

            $before[] = [
                'schedule_id' => $schedule->id,
                'period_number' => $schedule->period_number,
                'principal_due' => (float) $schedule->principal_due,
                'interest_due' => (float) $schedule->interest_due,
                'penalty_amount' => (float) $schedule->penalty_amount,
                'principal_paid' => (float) $schedule->principal_paid,
                'interest_paid' => (float) $schedule->interest_paid,
                'penalty_paid' => (float) $schedule->penalty_paid,
            ];

            $schedule->update([
                'principal_due' => $schedule->principal_paid,
                'interest_due' => $schedule->interest_paid,
                'penalty_amount' => $schedule->penalty_paid,
                // `total_due` excludes penalty everywhere else in this codebase.
                'total_due' => round((float) $schedule->principal_paid + (float) $schedule->interest_paid, 2),
                'remaining_balance' => round($carriedPrincipal, 2),
                'status' => 'paid',
                'closed_by_adjustment_id' => $adjustment->id,
            ]);

            $after[] = [
                'schedule_id' => $schedule->id,
                'period_number' => $schedule->period_number,
                'principal_due' => (float) $schedule->principal_due,
                'interest_due' => (float) $schedule->interest_due,
                'penalty_amount' => (float) $schedule->penalty_amount,
                'collected' => $collected,
            ];
        }

        if ($after === []) {
            return;
        }

        $totalCollected = round(array_sum(array_column($after, 'collected')), 2);

        AuditLogService::log(
            'periods_closed',
            $adjustment,
            ['periods' => $before],
            ['periods' => $after, 'total_collected' => $totalCollected, 'carried_principal' => round($carriedPrincipal, 2)],
            sprintf(
                '%s closed %d partly paid period(s) of loan %s at the ₱%s collected on them and carried the rest forward.',
                $adjustment->adjustment_number,
                count($after),
                $loan->loan_account_number ?? $loan->application_number,
                number_format($totalCollected, 2),
            ),
        );
    }

    /**
     * The latest period paid in full, which a rebuilt schedule starts after.
     *
     * A period a reschedule closed is `paid` too, but only for what was
     * collected on it: the rest of it was carried into later periods, so
     * starting after it would move the borrower's next due date.
     */
    private function lastPaidInFull(Loan $loan): ?AmortizationSchedule
    {
        // reorder(), because the relation already sorts by period_number,
        // which would otherwise win and hand back the FIRST paid row.
        return $loan->amortizationSchedules()
            ->where('status', 'paid')
            ->whereNull('closed_by_adjustment_id')
            ->reorder('due_date', 'desc')
            ->first();
    }

    /**
     * How many instalments of the loan's length lie before a rebuilt schedule.
     *
     * The periods up to the latest one paid in full, less those a reschedule
     * closed: a closed period shares its instalment with the period that took
     * over its remainder, so it adds a period number but no length.
     */
    private function instalmentsBefore(Loan $loan): int
    {
        $lastPaidPeriod = (int) $loan->amortizationSchedules()
            ->where('status', 'paid')
            ->whereNull('closed_by_adjustment_id')
            ->max('period_number');

        $closedBefore = $loan->amortizationSchedules()
            ->where('period_number', '<', $lastPaidPeriod)
            ->whereNotNull('closed_by_adjustment_id')
            ->count();

        return $lastPaidPeriod - $closedBefore;
    }

    /**
     * The term that reschedules `$instalments` instalments of `$frequency`.
     *
     * Restructure and term-extension adjustments count instalments. A months
     * term paid monthly or at maturity counts them already. Any other loan is
     * rescheduled as a days term of that many whole instalments, which
     * LoanTermSchedule splits back into exactly that many.
     *
     * @return array{term: int, term_unit: string}
     */
    private function termForInstalments(Loan $loan, int $instalments, string $frequency): array
    {
        if (LoanTermSchedule::stepsByCalendarMonth($loan->term_unit->value, $frequency)) {
            return ['term' => $instalments, 'term_unit' => TermUnit::Months->value];
        }

        return [
            'term' => $instalments * LoanTermSchedule::PERIOD_DAYS[$frequency],
            'term_unit' => TermUnit::Days->value,
        ];
    }

    /**
     * The due date one cycle after `$latestDueDate`.
     *
     * A monthly or upon-maturity cycle lands on the next anchored date after
     * the row it continues from: the loan's anchor day, capped at a shorter
     * month's end. A loan started on the 31st rolls 28 Feb -> 31 Mar -> 30 Apr
     * instead of staying on the 28th. An open row an overflowing month step
     * pushed into the first days of the next month (3 Mar) rolls to that
     * month's anchored date (31 Mar); every other row rolls into the following
     * month. See LoanTermSchedule::nthAnchoredDateAfter().
     */
    private function nextCycleDueDate(Loan $loan, Carbon $latestDueDate): Carbon
    {
        return match ($loan->frequency) {
            'daily' => $latestDueDate->copy()->addDay(),
            'weekly' => $latestDueDate->copy()->addWeek(),
            'bi_weekly' => $latestDueDate->copy()->addDays(14),
            'semi_monthly' => $latestDueDate->copy()->addDays(15),
            'monthly', 'upon_maturity' => LoanTermSchedule::nthAnchoredDateAfter($latestDueDate, 1, $this->anchorDay($loan)),
        };
    }

    /**
     * The day of the month a loan's calendar-month due dates fall on: the day
     * it started, whichever row a rebuild or a roll-forward starts from.
     */
    private function anchorDay(Loan $loan): int
    {
        return $loan->start_date->day;
    }

    /**
     * Where a schedule rebuilt on `$tempLoan` ends: its last row's due date,
     * on `$loan`'s anchor day when it steps by calendar month.
     *
     * Set on the temporary loan before its schedule is built, because a single
     * payment at maturity takes its due date from `maturity_date`.
     */
    private function rescheduledMaturityDate(Loan $tempLoan, Loan $loan): Carbon
    {
        return LoanTermSchedule::maturityDate(
            Carbon::parse($tempLoan->start_date),
            (int) $tempLoan->term,
            $tempLoan->term_unit->value,
            $tempLoan->frequency,
            $this->anchorDay($loan),
        );
    }

    /**
     * Apply an approved adjustment to its loan's schedule.
     *
     * The checks before the transaction refuse, with a 422, an apply that was
     * wrong from the start. The transaction then locks in the order every loan
     * write takes (LoanWriteTransaction): the loan, then this adjustment
     * (lockAdjustmentRows()), and re-reads the adjustment under its lock,
     * because two applies that both passed the status check would otherwise
     * both rewrite the schedule. The second finds it already applied and is
     * answered with a 409, as is a deadlock or a lock wait timeout, with
     * nothing of it left.
     *
     * No collateral lock: no adjustment type changes what the loan pledges or
     * moves it into or out of Loan::PLEDGING_STATUSES (a restructure
     * adjustment keeps the loan's status on purpose; see applyRestructure()).
     *
     * The loan must still be released or ongoing, as it had to be for the
     * adjustment to be requested (createAdjustment()): one completed, closed
     * by a restructure, defaulted or voided since is a 422 when the request
     * reads it so, and a 409 when it moved between that read and the lock.
     *
     * This is the outermost transaction: LoanAdjustmentController::apply() and
     * the test helpers are the only callers.
     */
    public function applyAdjustment(LoanAdjustment $adjustment): LoanAdjustment
    {
        $this->guardStatus($adjustment, 'approved', 'apply');
        $this->assertPayloadFitsType($adjustment);

        if (! in_array($adjustment->loan?->status, Loan::ACTIVE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'loan' => 'Adjustments can only be applied to released or ongoing loans.',
            ]);
        }

        return LoanWriteTransaction::run(function () use ($adjustment) {
            $loan = $this->lockAdjustmentRows($adjustment, 'approved');

            if (! in_array($loan->status, Loan::ACTIVE_STATUSES, true)) {
                throw LoanWriteTransaction::conflict();
            }

            $this->assertTargetPrincipalUnchanged($adjustment, $loan);

            match ($adjustment->adjustment_type) {
                'restructure' => $this->applyRestructure($adjustment, $loan),
                'penalty_waiver' => $this->applyPenaltyWaiver($adjustment, $loan),
                'balance_adjustment' => $this->applyBalanceAdjustment($adjustment, $loan),
                'term_extension' => $this->applyTermExtension($adjustment, $loan),
            };

            $adjustment->update([
                'status' => 'applied',
                'applied_at' => now(),
            ]);

            return $adjustment;
        });
    }

    /**
     * Lock the adjustment's loan row, then the adjustment's own, and refuse
     * with a 409 unless the adjustment is still in `$expectedStatus`: pending
     * for an approve or a reject, approved for an apply.
     *
     * Two locking reads, in the order every loan write takes
     * (LoanWriteTransaction), and the first statements of the transaction. The
     * adjustment is refreshed from its locked row and handed the locked loan,
     * so the write works from what is committed rather than from what the
     * request read before the lock.
     *
     * @throws HttpResponseException 409 when the adjustment was decided, applied, or otherwise moved, since it was read
     */
    private function lockAdjustmentRows(LoanAdjustment $adjustment, string $expectedStatus): Loan
    {
        $loan = Loan::whereKey($adjustment->loan_id)->lockForUpdate()->first();
        $locked = LoanAdjustment::whereKey($adjustment->getKey())->lockForUpdate()->first();

        if ($loan === null || $locked === null || $locked->status !== $expectedStatus) {
            throw LoanWriteTransaction::conflict();
        }

        $adjustment->setRawAttributes($locked->getAttributes(), true);
        $adjustment->setRelation('loan', $loan);

        return $loan;
    }

    private function applyRestructure(LoanAdjustment $adjustment, Loan $loan): void
    {
        $newValues = $adjustment->ownNewValues();

        // Compute outstanding from unpaid schedules
        $unpaidSchedules = $loan->amortizationSchedules()
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->get();

        $outstanding = $unpaidSchedules->sum(fn ($s) => (float) $s->principal_due - (float) $s->principal_paid);

        // Instalments already behind the loan, and the period the new
        // schedule starts after, both from the periods paid in full.
        $instalmentsBefore = $this->instalmentsBefore($loan);
        $lastPaidSchedule = $this->lastPaidInFull($loan);

        $this->closeOpenSchedules($loan, $adjustment, $outstanding);
        $lastKeptPeriod = (int) $loan->amortizationSchedules()->max('period_number');

        // Update loan with new terms
        $newRate = $newValues['interest_rate'] ?? $loan->interest_rate;
        // `term` here counts instalments, like every restructure input.
        $newTerm = $newValues['term'] ?? count(LoanTermSchedule::instalments(
            Carbon::parse($loan->start_date),
            $loan->term,
            $loan->term_unit->value,
            $loan->frequency,
        ));
        $newFrequency = $newValues['frequency'] ?? $loan->frequency;

        // Build new schedule using a temporary loan state
        $tempLoan = $loan->replicate();
        $tempLoan->principal_amount = $outstanding;
        $tempLoan->interest_rate = $newRate;
        $tempLoan->fill($this->termForInstalments($loan, (int) $newTerm, $newFrequency));
        $tempLoan->frequency = $newFrequency;
        $tempLoan->interest_method = $loan->interest_method;

        // Use the last due date of paid schedules as new start date. The rows
        // still fall on the loan's own anchor day, not on that row's day,
        // starting at the next anchored date after it (see
        // LoanTermSchedule::nthAnchoredDateAfter()).
        $tempLoan->start_date = $lastPaidSchedule ? $lastPaidSchedule->due_date : $loan->start_date;
        $tempLoan->maturity_date = $this->rescheduledMaturityDate($tempLoan, $loan);

        $newSchedule = $this->loanService->buildAmortizationPreview($tempLoan, $this->anchorDay($loan));

        // Numbered on from every period kept, closed ones included.
        foreach ($newSchedule as $row) {
            AmortizationSchedule::create([
                'loan_id' => $loan->id,
                'period_number' => $lastKeptPeriod + $row['period_number'],
                'due_date' => $row['due_date'],
                'principal_due' => $row['principal_due'],
                'interest_due' => $row['interest_due'],
                'total_due' => $row['total_due'],
                'remaining_balance' => $row['remaining_balance'],
                'status' => 'pending',
            ]);
        }

        // The loan keeps its released/ongoing status on purpose. This adjustment
        // reschedules a balance that is still owed, and
        // RepaymentService::processRepayment() only accepts released/ongoing —
        // stamping 'restructured' here made the loan permanently uncollectible.
        // `restructured` now means one thing only: closed because its balance
        // moved to a NEW loan (see LoanService::closeRestructuredSource()).
        $loan->update([
            'interest_rate' => $newRate,
            ...$this->termForInstalments($loan, $instalmentsBefore + $newTerm, $newFrequency),
            'frequency' => $newFrequency,
            'maturity_date' => $tempLoan->maturity_date,
        ]);
    }

    private function applyPenaltyWaiver(LoanAdjustment $adjustment, Loan $loan): void
    {
        $newValues = $adjustment->ownNewValues();
        $waiveAll = $newValues['waive_all'] ?? false;

        // Waiving every penalty on the loan must be asked for explicitly.
        // Previously a payload with neither key fell through to the unfiltered
        // query below and wiped them all — silently, and irreversibly.
        // StoreLoanAdjustmentRequest rejects that payload; this is the second line.
        if (! $waiveAll && empty($newValues['schedule_ids'])) {
            throw ValidationException::withMessages([
                'new_values.schedule_ids' => 'Select the schedules to waive, or set waive_all to true.',
            ]);
        }

        $before = [];
        $after = [];

        // A waiver forgives only the penalty still owed. The charge comes down
        // to what the borrower has already paid, and `penalty_paid` is never
        // touched: zeroing it, as this used to, erased penalty that a posted
        // receipt still carries, so Total Paid dropped and a later void took
        // the missing penalty out of interest or principal instead.
        //
        // The period keeps a link to this waiver, which is what stops
        // RepaymentService::applyPenalties() charging it again on the next
        // payment or the next night.
        $this->selectedOpenSchedules($loan, $newValues)->each(function (AmortizationSchedule $schedule) use ($adjustment, &$before, &$after) {
            $charged = (float) $schedule->penalty_amount;
            $paid = (float) $schedule->penalty_paid;
            $owed = $this->penaltyOwed($schedule);

            if ($owed > 0) {
                $schedule->update(['penalty_amount' => $paid, 'penalty_waiver_id' => $adjustment->id]);

                $before[] = [
                    'schedule_id' => $schedule->id,
                    'period_number' => $schedule->period_number,
                    'penalty_amount' => $charged,
                    'penalty_paid' => $paid,
                ];
                $after[] = [
                    'schedule_id' => $schedule->id,
                    'period_number' => $schedule->period_number,
                    'penalty_amount' => $paid,
                    'penalty_paid' => $paid,
                    'waived' => $owed,
                ];
            }

            // Recalculate status if overdue was only due to penalty
            if ($schedule->status === 'overdue') {
                $principalFullyPaid = (float) $schedule->principal_paid >= (float) $schedule->principal_due;
                $interestFullyPaid = (float) $schedule->interest_paid >= (float) $schedule->interest_due;

                if ($principalFullyPaid && $interestFullyPaid) {
                    $schedule->update(['status' => 'paid']);
                }
            }
        });

        // Periods are not audited models, so this row is the only record of
        // what the waiver forgave on each one, who applied it, and when.
        $totalWaived = round(array_sum(array_column($after, 'waived')), 2);

        AuditLogService::log(
            'penalty_waived',
            $adjustment,
            ['periods' => $before],
            ['periods' => $after, 'total_waived' => $totalWaived],
            sprintf(
                'Penalty waiver %s forgave ₱%s of unpaid penalty on loan %s.',
                $adjustment->adjustment_number,
                number_format($totalWaived, 2),
                $loan->loan_account_number ?? $loan->application_number,
            ),
        );
    }

    /**
     * Spread a balance adjustment over the loan's open periods, in proportion
     * to the principal each still owes, so the periods change by exactly the
     * amount approved, to the centavo.
     *
     * Worked in whole centavos (balanceAdjustmentShares()). Paid periods are
     * never touched. A reduction larger than the unpaid principal is refused
     * (422 on `new_values.adjustment_amount`) rather than capped, so what is
     * applied is always what was approved. A reduction that leaves a period
     * owing nothing marks it `paid`, and one that leaves no open period
     * completes the loan.
     */
    private function applyBalanceAdjustment(LoanAdjustment $adjustment, Loan $loan): void
    {
        $adjustmentAmount = (float) $adjustment->ownNewValues()['adjustment_amount'];

        $unpaidSchedules = $loan->amortizationSchedules()
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->orderBy('period_number')
            ->get()
            ->values();

        $centavos = static fn (mixed $pesos): int => (int) round((float) $pesos * 100);
        $amount = $centavos($adjustmentAmount);
        $unpaid = $unpaidSchedules
            ->map(fn (AmortizationSchedule $s): int => self::unpaidPrincipalCentavos($s))
            ->all();
        $totalUnpaid = array_sum($unpaid);

        if ($amount < 0 && -$amount > $totalUnpaid) {
            throw ValidationException::withMessages([
                'new_values.adjustment_amount' => sprintf(
                    'A reduction of ₱%s is more than the ₱%s of principal still unpaid on this loan.',
                    number_format(-$amount / 100, 2),
                    number_format($totalUnpaid / 100, 2),
                ),
            ]);
        }

        if ($totalUnpaid <= 0) {
            return;
        }

        foreach ($this->balanceAdjustmentShares($amount, $unpaid) as $i => $share) {
            if ($share === 0) {
                continue;
            }

            $schedule = $unpaidSchedules[$i];
            $newPrincipalDue = $centavos($schedule->principal_due) + $share;
            $changes = [
                'principal_due' => $newPrincipalDue / 100,
                'total_due' => ($newPrincipalDue + $centavos($schedule->interest_due)) / 100,
            ];

            // A reduction that leaves the period owing nothing settles it, by
            // the rule a payment uses (RepaymentService::allocateToSchedule()).
            $penaltySettled = $centavos($schedule->penalty_amount) === 0
                || $centavos($schedule->penalty_paid) >= $centavos($schedule->penalty_amount);

            if ($share < 0
                && $centavos($schedule->principal_paid) >= $newPrincipalDue
                && $centavos($schedule->interest_paid) >= $centavos($schedule->interest_due)
                && $penaltySettled) {
                $changes['status'] = 'paid';
            }

            $schedule->update($changes);
        }

        // As a payment does (RepaymentService, step 6): a loan with no open
        // period left is completed. `completed` leaves the pledging statuses,
        // so no collateral guard applies.
        if ($amount < 0 && ! $loan->amortizationSchedules()->whereIn('status', ['pending', 'partial', 'overdue'])->exists()) {
            $loan->update(['status' => 'completed']);
        }

        $this->restateOpenRemainingBalances($loan);
    }

    /**
     * Each open period's `remaining_balance`: the principal still owed once
     * it is paid, run down from the loan's actual unpaid principal through the
     * open periods in period order (the order a payment allocates), in centavos. Read after the adjustment is
     * written, so it carries every earlier adjustment and payment.
     *
     * Paid periods, the rows an extension or a restructure closed among them,
     * are never rewritten: what they recorded stays as it was.
     */
    private function restateOpenRemainingBalances(Loan $loan): void
    {
        $centavos = static fn (mixed $pesos): int => (int) round((float) $pesos * 100);

        $open = $loan->amortizationSchedules()
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->reorder('period_number')
            ->orderBy('due_date')
            ->get();

        $balance = $open->sum(fn (AmortizationSchedule $s): int => self::unpaidPrincipalCentavos($s));

        foreach ($open as $schedule) {
            $balance -= self::unpaidPrincipalCentavos($schedule);

            if ($centavos($schedule->remaining_balance) !== $balance) {
                $schedule->update(['remaining_balance' => $balance / 100]);
            }
        }
    }

    /**
     * Each open period's share of a balance adjustment, in centavos, adding up
     * to exactly `$amount`.
     *
     * Each share is the period's part of the unpaid principal, rounded; the
     * rounding remainder goes to the last period that still owes principal. A
     * reduction never takes a period below what has been paid on it: a share
     * a period cannot absorb moves to the periods before it that still can.
     *
     * @param  int  $amount  centavos; negative reduces
     * @param  list<int>  $unpaid  each period's unpaid principal, in centavos
     * @return list<int>
     */
    private function balanceAdjustmentShares(int $amount, array $unpaid): array
    {
        $total = array_sum($unpaid);
        $shares = array_fill(0, count($unpaid), 0);
        $owing = array_keys(array_filter($unpaid, fn (int $u): bool => $u > 0));
        $last = end($owing);

        foreach ($owing as $i) {
            if ($i !== $last) {
                $shares[$i] = (int) round($amount * $unpaid[$i] / $total);
            }
        }

        $shares[$last] = $amount - array_sum($shares);

        if ($amount >= 0) {
            return $shares;
        }

        // From the last period back: what a period cannot take, the one before it tries.
        $excess = 0;

        foreach (array_reverse($owing) as $i) {
            $wanted = $shares[$i] + $excess;
            $shares[$i] = max($wanted, -$unpaid[$i]);
            $excess = $wanted - $shares[$i];
        }

        return $shares;
    }

    private function applyTermExtension(LoanAdjustment $adjustment, Loan $loan): void
    {
        $additionalTerms = (int) $adjustment->ownNewValues()['additional_terms'];

        $unpaidSchedules = $loan->amortizationSchedules()
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->get();

        $outstanding = $unpaidSchedules->sum(fn ($s) => (float) $s->principal_due - (float) $s->principal_paid);

        // As in applyRestructure().
        $instalmentsBefore = $this->instalmentsBefore($loan);
        $lastPaidSchedule = $this->lastPaidInFull($loan);

        $this->closeOpenSchedules($loan, $adjustment, $outstanding);
        $lastKeptPeriod = (int) $loan->amortizationSchedules()->max('period_number');

        // Calculate remaining + additional terms
        $originalRemaining = $unpaidSchedules->count();
        $newTerm = $originalRemaining + $additionalTerms;

        // Build new schedule
        $tempLoan = $loan->replicate();
        $tempLoan->principal_amount = $outstanding;
        $tempLoan->fill($this->termForInstalments($loan, $newTerm, $loan->frequency));
        $tempLoan->start_date = $lastPaidSchedule ? $lastPaidSchedule->due_date : $loan->start_date;
        $tempLoan->maturity_date = $this->rescheduledMaturityDate($tempLoan, $loan);

        // On the loan's own anchor day, as applyRestructure() rebuilds.
        $newSchedule = $this->loanService->buildAmortizationPreview($tempLoan, $this->anchorDay($loan));

        foreach ($newSchedule as $row) {
            AmortizationSchedule::create([
                'loan_id' => $loan->id,
                'period_number' => $lastKeptPeriod + $row['period_number'],
                'due_date' => $row['due_date'],
                'principal_due' => $row['principal_due'],
                'interest_due' => $row['interest_due'],
                'total_due' => $row['total_due'],
                'remaining_balance' => $row['remaining_balance'],
                'status' => 'pending',
            ]);
        }

        $loan->update([
            ...$this->termForInstalments($loan, $instalmentsBefore + $newTerm, $loan->frequency),
            'maturity_date' => $tempLoan->maturity_date,
        ]);
    }

    /**
     * The open periods a penalty waiver covers: all of them on `waive_all`,
     * otherwise the selected `schedule_ids`.
     *
     * @param  array<string, mixed>  $newValues
     */
    private function selectedOpenSchedules(Loan $loan, array $newValues): Builder
    {
        $query = AmortizationSchedule::where('loan_id', $loan->id)
            ->whereIn('status', AmortizationSchedule::UNPAID_STATUSES)
            ->orderBy('period_number');

        if (! ($newValues['waive_all'] ?? false)) {
            $query->whereIn('id', $newValues['schedule_ids'] ?? []);
        }

        return $query;
    }

    /**
     * The penalty still owed on a period: the most a waiver can forgive there.
     */
    private function penaltyOwed(AmortizationSchedule $schedule): float
    {
        return round(max(0, (float) $schedule->penalty_amount - (float) $schedule->penalty_paid), 2);
    }

    /**
     * @param  array<string, mixed>  $newValues
     * @return array<string, mixed>
     */
    private function captureOldValues(Loan $loan, string $type, array $newValues): array
    {
        return match ($type) {
            'restructure' => [
                'outstanding_balance' => $loan->amortizationSchedules()
                    ->whereIn('status', ['pending', 'partial', 'overdue'])
                    ->get()
                    ->sum(fn ($s) => (float) $s->principal_due - (float) $s->principal_paid),
                'interest_rate' => (float) $loan->interest_rate,
                'term' => $loan->term,
                'frequency' => $loan->frequency,
                'maturity_date' => $loan->maturity_date->toDateString(),
            ],
            // What the waiver would forgive, period by period, as of the
            // request: only open periods it covers that still owe penalty.
            'penalty_waiver' => [
                'penalties' => $this->selectedOpenSchedules($loan, $newValues)
                    ->get()
                    ->filter(fn (AmortizationSchedule $s) => $this->penaltyOwed($s) > 0)
                    ->map(fn (AmortizationSchedule $s) => [
                        'schedule_id' => $s->id,
                        'period_number' => $s->period_number,
                        'penalty_amount' => (float) $s->penalty_amount,
                        'penalty_paid' => (float) $s->penalty_paid,
                        'penalty_owed' => $this->penaltyOwed($s),
                    ])->values()->toArray(),
            ],
            'balance_adjustment' => [
                'outstanding_principal' => $this->outstandingPrincipalCentavos($loan) / 100,
            ],
            'term_extension' => [
                'term' => $loan->term,
                'maturity_date' => $loan->maturity_date->toDateString(),
                'remaining_schedules' => $loan->amortizationSchedules()
                    ->whereIn('status', ['pending', 'partial', 'overdue'])
                    ->count(),
            ],
        };
    }

    /**
     * Refuse to approve or apply a request whose fields are not its type's.
     *
     * Validation has rejected such payloads at creation since 2026-10-02, but
     * rows written before then can carry another type's fields, or none of
     * their own: portfolio's ADJ-000018 is a penalty waiver whose whole
     * payload is `{interest_rate: 4}`. Approving one records a decision about
     * figures that will never apply. Rejecting it stays open.
     *
     * @throws ValidationException on `new_values`
     */
    private function assertPayloadFitsType(LoanAdjustment $adjustment): void
    {
        $type = $adjustment->adjustment_type;
        $label = str_replace('_', ' ', $type);
        $own = $adjustment->ownNewValues();
        $foreign = LoanAdjustment::foreignFields($type, $adjustment->new_values ?? []);

        if ($foreign !== []) {
            throw ValidationException::withMessages([
                'new_values' => sprintf(
                    '%s is a %s but carries %s, which a %s does not take. Reject it and request a new one.',
                    $adjustment->adjustment_number,
                    $label,
                    implode(', ', $foreign),
                    $label,
                ),
            ]);
        }

        $missing = match ($type) {
            'restructure' => $own === [] ? 'interest_rate, term or frequency' : null,
            'penalty_waiver' => empty($own['waive_all']) && empty($own['schedule_ids']) ? 'waive_all or schedule_ids' : null,
            'balance_adjustment' => is_numeric($own['adjustment_amount'] ?? null) ? null : 'adjustment_amount',
            'term_extension' => (int) ($own['additional_terms'] ?? 0) >= 1 ? null : 'additional_terms',
        };

        if ($missing !== null) {
            throw ValidationException::withMessages([
                'new_values' => sprintf(
                    '%s is a %s but has no %s. Reject it and request a new one.',
                    $adjustment->adjustment_number,
                    $label,
                    $missing,
                ),
            ]);
        }
    }

    private function guardStatus(LoanAdjustment $adjustment, string $expected, string $action): void
    {
        if ($adjustment->status !== $expected) {
            throw ValidationException::withMessages([
                'status' => ["Adjustment must be in '{$expected}' status to {$action}."],
            ]);
        }
    }
}
