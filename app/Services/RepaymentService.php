<?php

namespace App\Services;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\Repayment;
use App\Models\User;
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
    ): Repayment {
        if (! in_array($loan->status, ['released', 'ongoing'])) {
            throw ValidationException::withMessages([
                'loan' => 'Repayments can only be recorded for released or ongoing loans.',
            ]);
        }

        $paymentDate = Carbon::parse($paymentDate);

        return DB::transaction(function () use ($loan, $amountPaid, $paymentDate, $user, $remarks, $method, $referenceNumber) {
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
                $loan->update(['status' => 'ongoing']);
            }

            // NOTE: SCB crediting is frontend-driven (see frontend PR #106).
            // The frontend computes the actual excess allocation and posts to /api/share-capital/ledger
            // separately after this endpoint returns. Do not auto-credit here.

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
            $repayment = $this->processRepayment($loan, $amountPaid, $paymentDate, $user);

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

        return DB::transaction(function () use ($repayment, $reason, $user) {
            $loan = $repayment->loan;

            // Reverse allocation from schedules — we need to know per-schedule amounts.
            // We stored totals only, so we reverse using a proportional approach:
            // Re-run the allocation simulation and reverse each schedule.
            $this->reverseAllocation($repayment);

            // NOTE: SCB reversal is frontend-driven (see frontend PR #106).
            // The caller that voids a repayment is responsible for posting an offsetting debit
            // entry to /api/share-capital/ledger if this loan had SCB crediting on the original payment.

            $repayment->update([
                'status' => 'voided',
                'void_reason' => $reason,
                'voided_by' => $user->id,
                'voided_at' => now(),
            ]);

            // If loan was completed, revert appropriately based on remaining payments
            if ($loan->status === 'completed') {
                $remainingPayments = $loan->repayments()
                    ->where('status', 'posted')
                    ->where('id', '!=', $repayment->id)
                    ->count();

                $loan->update(['status' => $remainingPayments > 0 ? 'ongoing' : 'released']);
            }

            return $repayment;
        });
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

        $overdueSchedules = $schedules->filter(
            fn ($s) => $s->due_date->lt($today) && in_array($s->status, ['pending', 'partial', 'overdue'])
        );
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
     */
    public function applyPenalties(Loan $loan, Carbon $asOfDate): void
    {
        $penaltyRate = (float) $loan->penalty_rate;

        if ($penaltyRate <= 0) {
            return;
        }

        $loan->amortizationSchedules()
            ->where('due_date', '<', $asOfDate)
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->each(function (AmortizationSchedule $schedule) use ($penaltyRate) {
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
                $schedule->status = $schedule->due_date->lt($paymentDate) ? 'overdue' : 'pending';
                $schedule->penalty_amount = 0;
            } else {
                $schedule->status = 'partial';
            }

            $schedule->save();
        }
    }
}
