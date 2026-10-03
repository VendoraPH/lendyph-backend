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

    public function approveAdjustment(LoanAdjustment $adjustment, User $approver, ?string $remarks): LoanAdjustment
    {
        $this->guardStatus($adjustment, 'pending', 'approve');
        $this->assertPayloadFitsType($adjustment);

        $adjustment->update([
            'status' => 'approved',
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'remarks' => $remarks ?? $adjustment->remarks,
        ]);

        return $adjustment;
    }

    public function rejectAdjustment(LoanAdjustment $adjustment, User $approver, ?string $remarks): LoanAdjustment
    {
        $this->guardStatus($adjustment, 'pending', 'reject');

        $adjustment->update([
            'status' => 'rejected',
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'remarks' => $remarks ?? $adjustment->remarks,
        ]);

        return $adjustment;
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
        // Extension is limited to one-month-term loans, whatever the product.
        // Loan::isOneMonthTerm() reads the ORIGINAL term rather than the
        // current one — each extension below bumps `term`, so checking the
        // stored value would allow exactly one extension and then lock the
        // loan out. A one-month loan can roll forward as often as needed.
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

        // A deadlock or lock wait timeout is a 409, not a 500. This is the
        // outermost transaction: LoanController::extend() and the test helpers
        // are the only callers. processRepayment() below nests inside it.
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
            // Allocation inside processRepayment runs penalty → interest →
            // principal, so where a penalty is outstanding this amount is
            // consumed by that first and some interest still carries. That
            // matches what the UI did before this moved server-side, and the
            // carry figures below are re-read afterwards, so the arithmetic
            // stays correct either way.
            $interestPaid = 0.0;
            $interestRepayment = null;

            if ($interestOption === 'pay' && $interestBefore > 0) {
                $interestRepayment = $this->repaymentService->processRepayment(
                    $loan,
                    $interestBefore,
                    now()->toDateString(),
                    $user,
                    $remarks ?: '[EXTENSION INTEREST]',
                );

                $interestPaid = $interestBefore;
            }

            // Re-read: on the 'pay' path the rows above have just been
            // allocated against, so this is what genuinely remains.
            $openSchedules = $this->openSchedules($loan);

            $carryPrincipal = round($openSchedules->sum(
                fn ($s) => (float) $s->principal_due - (float) $s->principal_paid,
            ), 2);
            $carryInterest = $this->outstandingInterest($openSchedules);

            $latestOpenDueDate = Carbon::parse($openSchedules->max('due_date'));
            $maxPeriodNumber = (int) $loan->amortizationSchedules()->max('period_number');

            // One month's interest at the loan's rate, whatever period it is
            // quoted per. One flat cycle regardless of how many days it spans.
            $freshInterest = round($carryPrincipal * LoanTermSchedule::rateForDays(
                (float) $loan->interest_rate,
                $loan->interest_rate_frequency->value,
                LoanTermSchedule::DAYS_PER_MONTH,
            ), 2);

            $newDueDate = $this->nextCycleDueDate($loan, $latestOpenDueDate);

            // On 'defer' this is where the stacking happens: the unpaid interest
            // is still in $carryInterest and the fresh cycle is added on top.
            $newInterestDue = round($carryInterest + $freshInterest, 2);
            $newTotalDue = round($carryPrincipal + $newInterestDue, 2);
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
     * This is the outermost transaction: LoanAdjustmentController::apply() and
     * the test helpers are the only callers.
     */
    public function applyAdjustment(LoanAdjustment $adjustment): LoanAdjustment
    {
        $this->guardStatus($adjustment, 'approved', 'apply');
        $this->assertPayloadFitsType($adjustment);

        return LoanWriteTransaction::run(function () use ($adjustment) {
            $loan = $this->lockAdjustmentRows($adjustment);

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
     * with a 409 unless the adjustment is still approved.
     *
     * Two locking reads, in the order every loan write takes
     * (LoanWriteTransaction). The adjustment is refreshed from its locked row
     * and handed the locked loan, so the apply works from what is committed
     * rather than from what the request read before the lock.
     *
     * @throws HttpResponseException 409 when the adjustment was applied, or otherwise moved, since it was read
     */
    private function lockAdjustmentRows(LoanAdjustment $adjustment): Loan
    {
        $loan = Loan::whereKey($adjustment->loan_id)->lockForUpdate()->first();
        $locked = LoanAdjustment::whereKey($adjustment->getKey())->lockForUpdate()->first();

        if ($loan === null || $locked === null || $locked->status !== 'approved') {
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

    private function applyBalanceAdjustment(LoanAdjustment $adjustment, Loan $loan): void
    {
        $adjustmentAmount = (float) $adjustment->ownNewValues()['adjustment_amount'];

        $unpaidSchedules = $loan->amortizationSchedules()
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->orderBy('period_number')
            ->get();

        if ($unpaidSchedules->isEmpty()) {
            return;
        }

        $totalUnpaidPrincipal = $unpaidSchedules->sum(fn ($s) => (float) $s->principal_due - (float) $s->principal_paid);

        if ($totalUnpaidPrincipal <= 0) {
            return;
        }

        // Distribute proportionally
        $runningBalance = null;
        foreach ($unpaidSchedules as $i => $schedule) {
            $unpaidPrincipal = (float) $schedule->principal_due - (float) $schedule->principal_paid;
            $proportion = $unpaidPrincipal / $totalUnpaidPrincipal;
            $adjustForThis = round($adjustmentAmount * $proportion, 2);

            $newPrincipalDue = max(0, round((float) $schedule->principal_due + $adjustForThis, 2));
            $newTotalDue = round($newPrincipalDue + (float) $schedule->interest_due, 2);

            $schedule->update([
                'principal_due' => $newPrincipalDue,
                'total_due' => $newTotalDue,
            ]);
        }

        // Recalculate remaining_balance chain
        $allSchedules = $loan->amortizationSchedules()->orderBy('period_number')->get();
        $balance = (float) $loan->principal_amount + $adjustmentAmount;

        foreach ($allSchedules as $schedule) {
            $balance = round($balance - (float) $schedule->principal_due, 2);
            $schedule->update(['remaining_balance' => max(0, $balance)]);
        }
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
                'outstanding_principal' => $loan->amortizationSchedules()
                    ->whereIn('status', ['pending', 'partial', 'overdue'])
                    ->get()
                    ->sum(fn ($s) => (float) $s->principal_due - (float) $s->principal_paid),
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
