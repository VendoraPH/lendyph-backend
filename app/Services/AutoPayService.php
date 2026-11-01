<?php

namespace App\Services;

use App\Exceptions\CannotPostToTheBooksException;
use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Operates the CBS auto-pay flow: bulk-deduct due amortization across loans
 * with auto_pay = true, posting one Repayment per loan via RepaymentService.
 */
class AutoPayService
{
    public function __construct(private RepaymentService $repaymentService) {}

    private const SKIP_PART_PAID_UNSELECTED = 'Only part-paid periods are due, and none of them was selected.';

    private const SKIP_NOTHING_DUE = 'Nothing is due on the selected periods.';

    private const SKIP_EARLIER_ARREARS = 'Has earlier unpaid periods; collect them first.';

    private const SKIP_SETTLED_MEANWHILE = 'Nothing was due any more once the loan was locked: another payment settled it.';

    private const SKIP_CHANGED_MEANWHILE = 'Changed by another payment since the preview; run the preview again.';

    /**
     * Build the staff-facing preview for a date window, as of today (the date
     * a run posts on).
     *
     * Returns:
     *   summary       — totals across the loans a run would charge: principal,
     *                   interest, the late penalty the payment will charge
     *                   (RepaymentService::penaltyAsOf(), nothing written) and
     *                   their sum, `total_amount`
     *   loans         — the same per loan
     *   partial_rows  — schedule rows with amount_paid > 0 (already partially paid),
     *                   listed separately so staff can opt them in via include_schedule_ids
     *   skips         — loans a run would skip, with the reason: one with an
     *                   open period before the window is never charged
     *
     * `$includeScheduleIds` are the part-paid rows staff selected, as process()
     * takes them; each must be one of `partial_rows` (422 otherwise). Their
     * remaining amount is `summary.partial_total`, and `summary.grand_total`
     * adds it to `total_amount`: what a run posts for the same selection.
     *
     * @param  list<int|string>  $includeScheduleIds
     */
    public function preview(array $productIds, string $dateFrom, string $dateTo, array $includeScheduleIds = []): array
    {
        [$from, $to] = $this->parseRange($dateFrom, $dateTo);
        $asOf = now();

        $loans = $this->eligibleLoansQuery($productIds)
            ->with(['borrower:id,first_name,middle_name,last_name,suffix'])
            ->get();

        $totals = ['principal' => 0, 'interest' => 0, 'penalty' => 0];
        $loanRows = [];
        $partialRows = [];
        $partialRemaining = [];
        $skips = [];

        foreach ($loans as $loan) {
            $schedules = $this->dueSchedules($loan, $from, $to);

            if ($schedules->isEmpty()) {
                continue;
            }

            if ($this->hasEarlierArrears($loan, $from)) {
                $skips[] = $this->skip($loan, self::SKIP_EARLIER_ARREARS);

                continue;
            }

            $loanTotals = ['principal' => 0, 'interest' => 0, 'penalty' => 0];
            $loanHasFullDueRow = false;

            foreach ($schedules as $schedule) {
                $principalRemaining = max(0, $this->centavos($schedule->principal_due) - $this->centavos($schedule->principal_paid));
                $interestRemaining = max(0, $this->centavos($schedule->interest_due) - $this->centavos($schedule->interest_paid));
                $penaltyRemaining = $this->penaltyRemainingAsOf($loan, $schedule, $asOf);
                $amountPaid = $this->centavos($schedule->principal_paid) + $this->centavos($schedule->interest_paid) + $this->centavos($schedule->penalty_paid);
                $remainingBalance = $principalRemaining + $interestRemaining + $penaltyRemaining;

                if ($amountPaid > 0 && $remainingBalance > 0) {
                    $partialRemaining[(int) $schedule->id] = $remainingBalance;
                    $partialRows[] = [
                        'loan_id' => $loan->id,
                        'schedule_id' => $schedule->id,
                        'borrower_name' => $this->shortBorrowerName($loan),
                        'loan_account' => $loan->loan_account_number,
                        'due_date' => $schedule->due_date->toDateString(),
                        'period_number' => (int) $schedule->period_number,
                        'total_due' => ($this->centavos($schedule->total_due) + $penaltyRemaining) / 100,
                        'amount_paid' => $amountPaid / 100,
                        'remaining_balance' => $remainingBalance / 100,
                        'principal_remaining' => $principalRemaining / 100,
                        'interest_remaining' => $interestRemaining / 100,
                        'penalty_remaining' => $penaltyRemaining / 100,
                    ];

                    continue;
                }

                $loanTotals['principal'] += $principalRemaining;
                $loanTotals['interest'] += $interestRemaining;
                $loanTotals['penalty'] += $penaltyRemaining;
                $loanHasFullDueRow = true;
            }

            if ($loanHasFullDueRow) {
                foreach ($loanTotals as $key => $centavos) {
                    $totals[$key] += $centavos;
                }

                $loanRows[] = [
                    'loan_id' => $loan->id,
                    'loan_account_number' => $loan->loan_account_number,
                    'borrower_name' => $this->shortBorrowerName($loan),
                    'total_principal' => $loanTotals['principal'] / 100,
                    'total_interest' => $loanTotals['interest'] / 100,
                    'total_penalty' => $loanTotals['penalty'] / 100,
                    'total_amount' => array_sum($loanTotals) / 100,
                ];
            }
        }

        $partialTotal = 0;
        $counted = [];

        foreach (array_values($includeScheduleIds) as $index => $id) {
            $scheduleId = (int) $id;

            if (! array_key_exists($scheduleId, $partialRemaining)) {
                throw ValidationException::withMessages([
                    "include_schedule_ids.{$index}" => "Schedule {$scheduleId} is not one of this preview's part-paid rows.",
                ]);
            }

            // A row selected twice is charged once, as a run charges it.
            if (! isset($counted[$scheduleId])) {
                $counted[$scheduleId] = true;
                $partialTotal += $partialRemaining[$scheduleId];
            }
        }

        return [
            'summary' => [
                'total_principal' => $totals['principal'] / 100,
                'total_interest' => $totals['interest'] / 100,
                'total_penalty' => $totals['penalty'] / 100,
                'total_amount' => array_sum($totals) / 100,
                'partial_total' => $partialTotal / 100,
                'grand_total' => (array_sum($totals) + $partialTotal) / 100,
                'loans_count' => count($loanRows),
            ],
            'loans' => $loanRows,
            'partial_rows' => $partialRows,
            'skips' => $skips,
        ];
    }

    /**
     * Process auto-pay for the eligible loan set.
     *
     * - One Repayment per loan, method = 'auto_pay'.
     * - amount = sum of remaining due across all included schedules for that loan
     *   (full-due rows in [from, to] + any partial rows whose ids appear in $includeScheduleIds),
     *   late penalty included, worked out under the payment's loan lock, so a
     *   payment landing after the first read is never charged again.
     * - A loan with an open period before the window is skipped: the payment
     *   would land on periods staff did not select.
     * - Each skipped loan is listed in `skips` with the reason, each failed
     *   one in `failures`.
     * - Per-loan failures are isolated; the run continues and reports skipped/failed.
     */
    public function process(array $productIds, string $dateFrom, string $dateTo, array $includeScheduleIds, User $user): array
    {
        [$from, $to] = $this->parseRange($dateFrom, $dateTo);

        $loans = $this->eligibleLoansQuery($productIds)->get();

        $processed = 0;
        $skipped = 0;
        $repayments = [];
        $skips = [];
        $failures = [];

        foreach ($loans as $loan) {
            // A first look, to skip loans with nothing to do. The amount itself
            // is worked out again under the loan's lock, below.
            $allSchedules = $this->dueSchedules($loan, $from, $to);

            if ($allSchedules->isEmpty()) {
                continue;
            }

            $reason = match (true) {
                $this->hasEarlierArrears($loan, $from) => self::SKIP_EARLIER_ARREARS,
                $this->filterIncludedSchedules($allSchedules, $includeScheduleIds)->isEmpty() => self::SKIP_PART_PAID_UNSELECTED,
                $this->totalRemaining($this->filterIncludedSchedules($allSchedules, $includeScheduleIds)) <= 0 => self::SKIP_NOTHING_DUE,
                default => null,
            };

            if ($reason !== null) {
                $skipped++;
                $skips[] = $this->skip($loan, $reason);

                continue;
            }

            try {
                // Under the payment's own lock, after its penalty step, from
                // what is committed then. Why nothing was charged, if so, is
                // read from the same moment.
                $whyNothing = null;

                $repayment = $this->repaymentService->processRepaymentWithAmountUnderLock(
                    loan: $loan,
                    amountUnderLock: function (Loan $locked) use ($from, $to, $includeScheduleIds, &$whyNothing): float {
                        if ($this->hasEarlierArrears($locked, $from)) {
                            $whyNothing = self::SKIP_EARLIER_ARREARS;

                            return 0;
                        }

                        $due = $this->dueSchedules($locked, $from, $to);
                        $amount = $this->totalRemaining($this->filterIncludedSchedules($due, $includeScheduleIds));

                        if ($amount <= 0) {
                            $whyNothing = $this->totalRemaining($due) > 0 ? self::SKIP_CHANGED_MEANWHILE : self::SKIP_SETTLED_MEANWHILE;
                        }

                        return $amount;
                    },
                    paymentDate: now()->toDateString(),
                    user: $user,
                    remarks: 'Auto-pay run '.$from->toDateString().' → '.$to->toDateString(),
                    method: 'auto_pay',
                    referenceNumber: $loan->cbs_reference,
                );

                if ($repayment === null) {
                    $skipped++;
                    $skips[] = $this->skip($loan, $whyNothing ?? self::SKIP_SETTLED_MEANWHILE);

                    continue;
                }

                $processed++;
                $repayments[] = [
                    'loan_id' => $loan->id,
                    'repayment_id' => $repayment->id,
                    'amount_paid' => (float) $repayment->amount_paid,
                ];
            } catch (Throwable $e) {
                $failures[] = [
                    'loan_id' => $loan->id,
                    'loan_account_number' => $loan->loan_account_number,
                    'reason' => $this->failureReason($e),
                ];

                // The message is the whole value of this exception. A refused
                // posting (CannotPostToTheBooksException) carries the one
                // sentence that says WHICH accounting setting is missing —
                // swallowing it turns a five-minute fix into "N failed" with
                // nothing to act on, across an entire overnight run.
                Log::warning('Auto-pay skipped a loan after an error.', [
                    'loan_id' => $loan->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'processed' => $processed,
            'skipped' => $skipped,
            'failed' => count($failures),
            'repayments' => $repayments,
            'skips' => $skips,
            'failures' => $failures,
        ];
    }

    /**
     * Toggle auto-pay on a single loan.
     *
     * - $enabled = true requires a non-empty cbs_reference.
     * - The loan must be in Loan::ACTIVE_STATUSES either way: auto-pay only
     *   means anything for a loan that is out the door and still owes.
     * - $enabled = false clears the reference and the audit columns.
     *
     * This list used to carry a third value, `past_due`, which the
     * `loans.status` enum has never held — so it allowed nothing, while the
     * 422 below promised callers a status no loan can be in. A past-due loan
     * is an `ongoing` (or `released`) loan with an overdue schedule, and is
     * already covered by those two. See Loan::scopePastDue().
     */
    public function toggle(Loan $loan, bool $enabled, ?string $cbsReference, User $user): Loan
    {
        if (! in_array($loan->status, Loan::ACTIVE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'loan' => 'Auto-pay can only be toggled on released or ongoing loans.',
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
            // Same set as toggle() enforces. `past_due` used to be listed here
            // too and matched nothing — it is not a member of the enum.
            ->whereIn('status', Loan::ACTIVE_STATUSES)
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
     * Whether the loan has an open period due before the window. A payment
     * allocates oldest first, so a run on such a loan would land on periods
     * staff did not select.
     */
    private function hasEarlierArrears(Loan $loan, Carbon $from): bool
    {
        return $loan->amortizationSchedules()
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->where('due_date', '<', $from->toDateString())
            ->exists();
    }

    /**
     * What is left of a period's late penalty once the payment's penalty step
     * has run as of `$asOf`, in centavos, by the rule that step applies
     * (RepaymentService::penaltyAsOf()). Writes nothing.
     */
    private function penaltyRemainingAsOf(Loan $loan, AmortizationSchedule $schedule, Carbon $asOf): int
    {
        $charged = $this->repaymentService->penaltyAsOf($loan, $schedule, $asOf) ?? (float) $schedule->penalty_amount;

        return max(0, $this->centavos($charged) - $this->centavos($schedule->penalty_paid));
    }

    /**
     * @return array{loan_id: int, loan_account_number: string|null, reason: string}
     */
    private function skip(Loan $loan, string $reason): array
    {
        return ['loan_id' => $loan->id, 'loan_account_number' => $loan->loan_account_number, 'reason' => $reason];
    }

    /**
     * A failed loan's reason for staff: the refusal's own words where it was
     * written for them (a validation error, a 409, a refused posting), never
     * an unexpected error's internals, which stay in the log.
     */
    private function failureReason(Throwable $e): string
    {
        return match (true) {
            $e instanceof ValidationException => (string) collect($e->errors())->flatten()->first(),
            $e instanceof HttpResponseException => (string) (json_decode((string) $e->getResponse()->getContent(), true)['message'] ?? 'The payment was refused.'),
            $e instanceof CannotPostToTheBooksException => $e->getMessage(),
            default => 'An unexpected error stopped this payment; the details are in the server log.',
        };
    }

    private function centavos(mixed $pesos): int
    {
        return (int) round((float) ($pesos ?? 0) * 100);
    }

    /**
     * The loan's open periods falling due in the run's range, in period order.
     *
     * @return Collection<int, AmortizationSchedule>
     */
    private function dueSchedules(Loan $loan, Carbon $from, Carbon $to): Collection
    {
        return $loan->amortizationSchedules()
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('period_number')
            ->get();
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
