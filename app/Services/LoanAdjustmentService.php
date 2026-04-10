<?php

namespace App\Services;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoanAdjustmentService
{
    public function __construct(private LoanService $loanService) {}

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

        $loan->update([
            'interest_rate' => $newRate,
            'term' => $lastPaidPeriod + $newTerm,
            'frequency' => $newFrequency,
            'maturity_date' => $newMaturity,
            'status' => 'restructured',
        ]);
    }

    private function applyPenaltyWaiver(LoanAdjustment $adjustment, Loan $loan): void
    {
        $newValues = $adjustment->new_values;
        $waiveAll = $newValues['waive_all'] ?? false;

        $query = $loan->amortizationSchedules()
            ->whereIn('status', ['pending', 'partial', 'overdue']);

        if (! $waiveAll && ! empty($newValues['schedule_ids'])) {
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
