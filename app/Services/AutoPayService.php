<?php

namespace App\Services;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Operates the CBS auto-pay flow: bulk-deduct due amortization across loans
 * with auto_pay = true, posting one Repayment per loan via RepaymentService.
 */
class AutoPayService
{
    public function __construct(private RepaymentService $repaymentService) {}

    /**
     * Build the staff-facing preview for a date window.
     *
     * Returns:
     *   summary       — totals across loans whose due rows fully fall in [date_from, date_to]
     *   partial_rows  — schedule rows with amount_paid > 0 (already partially paid),
     *                   listed separately so staff can opt them in via include_schedule_ids
     */
    public function preview(array $productIds, string $dateFrom, string $dateTo): array
    {
        [$from, $to] = $this->parseRange($dateFrom, $dateTo);

        $loans = $this->eligibleLoansQuery($productIds)
            ->with(['borrower:id,first_name,middle_name,last_name,suffix'])
            ->get();

        $totalPrincipal = 0.0;
        $totalInterest = 0.0;
        $totalAmount = 0.0;
        $loansCount = 0;
        $partialRows = [];

        foreach ($loans as $loan) {
            $schedules = $loan->amortizationSchedules()
                ->whereIn('status', ['pending', 'partial', 'overdue'])
                ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
                ->orderBy('period_number')
                ->get();

            if ($schedules->isEmpty()) {
                continue;
            }

            $loanPrincipal = 0.0;
            $loanInterest = 0.0;
            $loanHasFullDueRow = false;

            foreach ($schedules as $schedule) {
                $principalRemaining = max(0, (float) $schedule->principal_due - (float) $schedule->principal_paid);
                $interestRemaining = max(0, (float) $schedule->interest_due - (float) $schedule->interest_paid);
                $penaltyRemaining = max(0, (float) ($schedule->penalty_amount ?? 0) - (float) ($schedule->penalty_paid ?? 0));
                $amountPaid = (float) $schedule->principal_paid + (float) $schedule->interest_paid + (float) ($schedule->penalty_paid ?? 0);
                $remainingBalance = $principalRemaining + $interestRemaining + $penaltyRemaining;

                if ($amountPaid > 0 && $remainingBalance > 0) {
                    $partialRows[] = [
                        'loan_id' => $loan->id,
                        'schedule_id' => $schedule->id,
                        'borrower_name' => $this->shortBorrowerName($loan),
                        'loan_account' => $loan->loan_account_number,
                        'due_date' => $schedule->due_date->toDateString(),
                        'period_number' => (int) $schedule->period_number,
                        'total_due' => round((float) $schedule->total_due + $penaltyRemaining, 2),
                        'amount_paid' => round($amountPaid, 2),
                        'remaining_balance' => round($remainingBalance, 2),
                        'principal_remaining' => round($principalRemaining, 2),
                        'interest_remaining' => round($interestRemaining, 2),
                    ];

                    continue;
                }

                $loanPrincipal += $principalRemaining;
                $loanInterest += $interestRemaining;
                $loanHasFullDueRow = true;
            }

            if ($loanHasFullDueRow) {
                $totalPrincipal += $loanPrincipal;
                $totalInterest += $loanInterest;
                $totalAmount += $loanPrincipal + $loanInterest;
                $loansCount++;
            }
        }

        return [
            'summary' => [
                'total_principal' => round($totalPrincipal, 2),
                'total_interest' => round($totalInterest, 2),
                'total_amount' => round($totalAmount, 2),
                'loans_count' => $loansCount,
            ],
            'partial_rows' => $partialRows,
        ];
    }

    /**
     * Process auto-pay for the eligible loan set.
     *
     * - One Repayment per loan, method = 'auto_pay'.
     * - amount = sum of remaining due across all included schedules for that loan
     *   (full-due rows in [from, to] + any partial rows whose ids appear in $includeScheduleIds).
     * - Per-loan failures are isolated; the run continues and reports skipped/failed.
     */
    public function process(array $productIds, string $dateFrom, string $dateTo, array $includeScheduleIds, User $user): array
    {
        [$from, $to] = $this->parseRange($dateFrom, $dateTo);

        $loans = $this->eligibleLoansQuery($productIds)->get();

        $processed = 0;
        $skipped = 0;
        $failed = 0;
        $repayments = [];

        foreach ($loans as $loan) {
            $allSchedules = $loan->amortizationSchedules()
                ->whereIn('status', ['pending', 'partial', 'overdue'])
                ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
                ->orderBy('period_number')
                ->get();

            if ($allSchedules->isEmpty()) {
                continue;
            }

            $included = $this->filterIncludedSchedules($allSchedules, $includeScheduleIds);

            if ($included->isEmpty()) {
                $skipped++;

                continue;
            }

            $amount = $this->totalRemaining($included);

            if ($amount <= 0) {
                $skipped++;

                continue;
            }

            try {
                $repayment = $this->repaymentService->processRepayment(
                    loan: $loan,
                    amountPaid: round($amount, 2),
                    paymentDate: now()->toDateString(),
                    user: $user,
                    remarks: 'Auto-pay run '.$from->toDateString().' → '.$to->toDateString(),
                    method: 'auto_pay',
                    referenceNumber: $loan->cbs_reference,
                );

                $processed++;
                $repayments[] = [
                    'loan_id' => $loan->id,
                    'repayment_id' => $repayment->id,
                    'amount_paid' => (float) $repayment->amount_paid,
                ];
            } catch (Throwable $e) {
                $failed++;
            }
        }

        return [
            'processed' => $processed,
            'skipped' => $skipped,
            'failed' => $failed,
            'repayments' => $repayments,
        ];
    }

    /**
     * Toggle auto-pay on a single loan.
     *
     * - $enabled = true requires non-empty cbs_reference and loan in
     *   {released, ongoing, past_due}.
     * - $enabled = false clears the reference and the audit columns.
     */
    public function toggle(Loan $loan, bool $enabled, ?string $cbsReference, User $user): Loan
    {
        $allowedStatuses = ['released', 'ongoing', 'past_due'];

        if (! in_array($loan->status, $allowedStatuses, true)) {
            throw ValidationException::withMessages([
                'loan' => 'Auto-pay can only be toggled on released, ongoing, or past-due loans.',
            ]);
        }

        if ($enabled) {
            if (! is_string($cbsReference) || trim($cbsReference) === '') {
                throw ValidationException::withMessages([
                    'cbs_reference' => 'cbs_reference is required when enabling auto-pay.',
                ]);
            }

            $loan->update([
                'auto_pay' => true,
                'cbs_reference' => $cbsReference,
                'auto_pay_enabled_at' => now(),
                'auto_pay_enabled_by' => $user->id,
            ]);
        } else {
            $loan->update([
                'auto_pay' => false,
                'cbs_reference' => null,
                'auto_pay_enabled_at' => null,
                'auto_pay_enabled_by' => null,
            ]);
        }

        return $loan->refresh();
    }

    private function eligibleLoansQuery(array $productIds)
    {
        return Loan::query()
            ->where('auto_pay', true)
            ->whereIn('status', ['released', 'ongoing', 'past_due'])
            ->when(! empty($productIds), fn ($q) => $q->whereIn('loan_product_id', $productIds));
    }

    private function parseRange(string $dateFrom, string $dateTo): array
    {
        $from = Carbon::parse($dateFrom)->startOfDay();
        $to = Carbon::parse($dateTo)->endOfDay();

        if ($from->gt($to)) {
            throw ValidationException::withMessages([
                'date_from' => 'date_from must be on or before date_to.',
            ]);
        }

        return [$from, $to];
    }

    private function shortBorrowerName(Loan $loan): ?string
    {
        $borrower = $loan->borrower;
        if (! $borrower) {
            return null;
        }

        $firstInitial = $borrower->first_name ? mb_substr($borrower->first_name, 0, 1).'.' : '';

        return trim($firstInitial.' '.($borrower->last_name ?? ''));
    }

    /**
     * Returns full-due rows + any partial rows whose ids appear in $includeScheduleIds.
     */
    private function filterIncludedSchedules(Collection $schedules, array $includeScheduleIds): Collection
    {
        $idSet = array_map('intval', $includeScheduleIds);

        return $schedules->filter(function (AmortizationSchedule $schedule) use ($idSet) {
            $amountPaid = (float) $schedule->principal_paid + (float) $schedule->interest_paid + (float) ($schedule->penalty_paid ?? 0);
            $isPartial = $amountPaid > 0;

            return $isPartial ? in_array((int) $schedule->id, $idSet, true) : true;
        })->values();
    }

    private function totalRemaining(Collection $schedules): float
    {
        return $schedules->sum(function (AmortizationSchedule $schedule) {
            return max(0, (float) $schedule->principal_due - (float) $schedule->principal_paid)
                + max(0, (float) $schedule->interest_due - (float) $schedule->interest_paid)
                + max(0, (float) ($schedule->penalty_amount ?? 0) - (float) ($schedule->penalty_paid ?? 0));
        });
    }
}
