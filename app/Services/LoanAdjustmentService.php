<?php

namespace App\Services;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\LoanLedgerEntry;
use App\Models\Repayment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

        $oldValues = $this->captureOldValues($loan, $validated['adjustment_type']);

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

        return DB::transaction(function () use ($loan, $user, $remarks, $interestOption) {
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

            // PH monthly-rate convention — same as LoanService::buildUponMaturity.
            // One flat cycle regardless of how many days the cycle spans.
            $freshInterest = round($carryPrincipal * ((float) $loan->interest_rate / 100), 2);

            $newDueDate = $this->stepNextPeriod($latestOpenDueDate, $loan->frequency);

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

            $loan->amortizationSchedules()
                ->whereIn('status', ['pending', 'partial', 'overdue'])
                ->delete();

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

    private function stepNextPeriod(Carbon $date, string $frequency): Carbon
    {
        return match ($frequency) {
            'daily' => $date->copy()->addDay(),
            'weekly' => $date->copy()->addWeek(),
            'bi_weekly' => $date->copy()->addDays(14),
            'semi_monthly' => $date->copy()->addDays(15),
            'monthly', 'upon_maturity' => $date->copy()->addMonth(),
        };
    }

    public function applyAdjustment(LoanAdjustment $adjustment): LoanAdjustment
    {
        $this->guardStatus($adjustment, 'approved', 'apply');

        return DB::transaction(function () use ($adjustment) {
            $loan = $adjustment->loan;

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

    private function applyRestructure(LoanAdjustment $adjustment, Loan $loan): void
    {
        $newValues = $adjustment->new_values;

        // Compute outstanding from unpaid schedules
        $unpaidSchedules = $loan->amortizationSchedules()
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->get();

        $outstanding = $unpaidSchedules->sum(fn ($s) => (float) $s->principal_due - (float) $s->principal_paid);

        // Last paid period number
        $lastPaidPeriod = $loan->amortizationSchedules()
            ->where('status', 'paid')
            ->max('period_number') ?? 0;

        // Delete unpaid schedules
        $loan->amortizationSchedules()
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->delete();

        // Update loan with new terms
        $newRate = $newValues['interest_rate'] ?? $loan->interest_rate;
        $newTerm = $newValues['term'] ?? $loan->term;
        $newFrequency = $newValues['frequency'] ?? $loan->frequency;

        // Build new schedule using a temporary loan state
        $tempLoan = $loan->replicate();
        $tempLoan->principal_amount = $outstanding;
        $tempLoan->interest_rate = $newRate;
        $tempLoan->term = $newTerm;
        $tempLoan->frequency = $newFrequency;
        $tempLoan->interest_method = $loan->interest_method;

        // Use the last due date of paid schedules as new start date
        $lastPaidSchedule = $loan->amortizationSchedules()->where('status', 'paid')->orderByDesc('due_date')->first();
        $tempLoan->start_date = $lastPaidSchedule ? $lastPaidSchedule->due_date : $loan->start_date;

        $newSchedule = $this->loanService->buildAmortizationPreview($tempLoan);

        // Persist with continued period numbers
        foreach ($newSchedule as $row) {
            AmortizationSchedule::create([
                'loan_id' => $loan->id,
                'period_number' => $lastPaidPeriod + $row['period_number'],
                'due_date' => $row['due_date'],
                'principal_due' => $row['principal_due'],
                'interest_due' => $row['interest_due'],
                'total_due' => $row['total_due'],
                'remaining_balance' => $row['remaining_balance'],
                'status' => 'pending',
            ]);
        }

        // Update loan record
        $newMaturity = $this->loanService->computeMaturityDate(
            $tempLoan->start_date instanceof Carbon
                ? $tempLoan->start_date->toDateString()
                : $tempLoan->start_date,
            $newTerm,
            $newFrequency,
        );

        // The loan keeps its released/ongoing status on purpose. This adjustment
        // reschedules a balance that is still owed, and
        // RepaymentService::processRepayment() only accepts released/ongoing —
        // stamping 'restructured' here made the loan permanently uncollectible.
        // `restructured` now means one thing only: closed because its balance
        // moved to a NEW loan (see LoanService::closeRestructuredSource()).
        $loan->update([
            'interest_rate' => $newRate,
            'term' => $lastPaidPeriod + $newTerm,
            'frequency' => $newFrequency,
            'maturity_date' => $newMaturity,
        ]);
    }

    private function applyPenaltyWaiver(LoanAdjustment $adjustment, Loan $loan): void
    {
        $newValues = $adjustment->new_values;
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

        $query = $loan->amortizationSchedules()
            ->whereIn('status', ['pending', 'partial', 'overdue']);

        if (! $waiveAll) {
            $query->whereIn('id', $newValues['schedule_ids']);
        }

        $query->each(function (AmortizationSchedule $schedule) {
            $schedule->update([
                'penalty_amount' => 0,
                'penalty_paid' => 0,
            ]);

            // Recalculate status if overdue was only due to penalty
            if ($schedule->status === 'overdue') {
                $principalFullyPaid = (float) $schedule->principal_paid >= (float) $schedule->principal_due;
                $interestFullyPaid = (float) $schedule->interest_paid >= (float) $schedule->interest_due;

                if ($principalFullyPaid && $interestFullyPaid) {
                    $schedule->update(['status' => 'paid']);
                }
            }
        });
    }

    private function applyBalanceAdjustment(LoanAdjustment $adjustment, Loan $loan): void
    {
        $adjustmentAmount = (float) $adjustment->new_values['adjustment_amount'];

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
        $additionalTerms = (int) $adjustment->new_values['additional_terms'];

        $unpaidSchedules = $loan->amortizationSchedules()
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->get();

        $outstanding = $unpaidSchedules->sum(fn ($s) => (float) $s->principal_due - (float) $s->principal_paid);

        $lastPaidPeriod = $loan->amortizationSchedules()
            ->where('status', 'paid')
            ->max('period_number') ?? 0;

        $lastPaidSchedule = $loan->amortizationSchedules()
            ->where('status', 'paid')
            ->orderByDesc('due_date')
            ->first();

        // Delete unpaid schedules
        $loan->amortizationSchedules()
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->delete();

        // Calculate remaining + additional terms
        $originalRemaining = $unpaidSchedules->count();
        $newTerm = $originalRemaining + $additionalTerms;

        // Build new schedule
        $tempLoan = $loan->replicate();
        $tempLoan->principal_amount = $outstanding;
        $tempLoan->term = $newTerm;
        $tempLoan->start_date = $lastPaidSchedule ? $lastPaidSchedule->due_date : $loan->start_date;

        $newSchedule = $this->loanService->buildAmortizationPreview($tempLoan);

        foreach ($newSchedule as $row) {
            AmortizationSchedule::create([
                'loan_id' => $loan->id,
                'period_number' => $lastPaidPeriod + $row['period_number'],
                'due_date' => $row['due_date'],
                'principal_due' => $row['principal_due'],
                'interest_due' => $row['interest_due'],
                'total_due' => $row['total_due'],
                'remaining_balance' => $row['remaining_balance'],
                'status' => 'pending',
            ]);
        }

        $newMaturity = $this->loanService->computeMaturityDate(
            $tempLoan->start_date instanceof Carbon
                ? $tempLoan->start_date->toDateString()
                : $tempLoan->start_date,
            $newTerm,
            $loan->frequency,
        );

        $loan->update([
            'term' => $lastPaidPeriod + $newTerm,
            'maturity_date' => $newMaturity,
        ]);
    }

    private function captureOldValues(Loan $loan, string $type): array
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
            'penalty_waiver' => [
                'penalties' => $loan->amortizationSchedules()
                    ->where('penalty_amount', '>', 0)
                    ->get()
                    ->map(fn ($s) => [
                        'schedule_id' => $s->id,
                        'period_number' => $s->period_number,
                        'penalty_amount' => (float) $s->penalty_amount,
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

    private function guardStatus(LoanAdjustment $adjustment, string $expected, string $action): void
    {
        if ($adjustment->status !== $expected) {
            throw ValidationException::withMessages([
                'status' => ["Adjustment must be in '{$expected}' status to {$action}."],
            ]);
        }
    }
}
