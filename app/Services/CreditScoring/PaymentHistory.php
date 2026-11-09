<?php

namespace App\Services\CreditScoring;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use Carbon\Carbon;

/**
 * How a member has paid: the outcome of every matured period on their
 * released loans, read from the schedule rows and the payments' allocations.
 *
 * A period is matured once its due date has come and it is either paid
 * (principal and interest) or past the loan's grace period unpaid. Left out:
 * - periods an extension, adjustment or restructure closed early;
 * - an imported loan's periods due before its arrears baseline, whose
 *   history belongs to the cooperative's old books;
 * - periods with nothing due.
 *
 * A loan restructured into a new loan stops being late on the restructure
 * date: an unpaid period on it is late only up to that date, and one due on
 * or after it isn't matured. Loans restructured before closed periods were
 * marked (`closed_by_loan_id`) still hold such periods open. Without a
 * restructure date, its unpaid periods are left out.
 *
 * A paid period's date is the payment date of the allocation that completed
 * its principal and interest. A period paid with no allocation that proves
 * when, or whose completing allocation is a payment from before allocations
 * were recorded (1 October 2026, then backfilled by replay), is a legacy
 * period: it counts as matured, and when its date is unknown it is left out
 * of the on-time and lateness measures. Legacy periods cap the confidence.
 */
final class PaymentHistory
{
    public const LEGACY_ALLOCATION_CUTOFF = '2026-10-01';

    public const CLEAN_LOAN_MAX_DAYS_LATE = 7;

    /**
     * @param  list<array{loan_id: int, due_date: string, on_time: bool|null, days_late: int|null, legacy: bool}>  $outcomes
     */
    private function __construct(
        public readonly array $outcomes,
        public readonly int $maturedCount,
        public readonly int $knownCount,
        public readonly int $onTimeCount,
        public readonly int $lateCount,
        public readonly int $legacyCount,
        public readonly ?int $worstDaysLate24m,
        public readonly int $daysPastDueNow,
        public readonly int $completedLoans,
        public readonly int $cleanCompletedLoans,
        public readonly int $defaultedLoans,
        public readonly int $writtenOffLoans,
        public readonly int $restructuredLoans,
        public readonly int $everReleasedLoans,
    ) {}

    public static function from(MemberCreditData $data): self
    {
        $asOf = $data->asOf;
        $loans = $data->loans->keyBy('id');
        $cutoff = Carbon::parse(self::LEGACY_ALLOCATION_CUTOFF)->startOfDay();
        $outcomes = [];
        $daysPastDueNow = 0;

        foreach ($data->periods as $period) {
            $loan = $loans->get($period->loan_id);
            $due = Carbon::parse($period->due_date)->startOfDay();
            $loanAsOf = self::lateUntil($loan, $asOf);
            $baseline = $loan->imported_arrears_baseline !== null ? Carbon::parse($loan->imported_arrears_baseline) : null;
            $owedCentavos = Centavos::of($period->principal_due) + Centavos::of($period->interest_due);
            $paidCentavos = Centavos::of($period->principal_paid) + Centavos::of($period->interest_paid);

            if ($period->closed_by_adjustment_id !== null || $period->closed_by_loan_id !== null
                || ! AmortizationSchedule::isPenalisable($baseline, $due)
                || $owedCentavos <= 0
                || ($loanAsOf === null && $paidCentavos < $owedCentavos)
                || $due->gt($loanAsOf ?? $asOf)) {
                continue;
            }

            $grace = max(0, (int) $loan->grace_period_days);
            $pastGrace = $due->lt(AmortizationSchedule::pastGraceCutoff($grace, $loanAsOf ?? $asOf));
            $fullyPaid = $paidCentavos >= $owedCentavos;

            if (! $fullyPaid && ! $pastGrace) {
                continue;
            }

            if (! $fullyPaid) {
                $daysLate = (int) $due->diffInDays($loanAsOf);
                $outcomes[] = ['loan_id' => (int) $loan->id, 'due_date' => $due->toDateString(), 'on_time' => false, 'days_late' => $daysLate, 'legacy' => false];

                if (in_array($loan->status, Loan::COLLECTIBLE_STATUSES, true)) {
                    $daysPastDueNow = max($daysPastDueNow, $daysLate);
                }

                continue;
            }

            $paidOn = self::completedOn($period->allocations, $owedCentavos);

            if ($paidOn === null) {
                $outcomes[] = ['loan_id' => (int) $loan->id, 'due_date' => $due->toDateString(), 'on_time' => null, 'days_late' => null, 'legacy' => true];

                continue;
            }

            $outcomes[] = [
                'loan_id' => (int) $loan->id,
                'due_date' => $due->toDateString(),
                'on_time' => $paidOn->lte($due->copy()->addDays($grace)),
                'days_late' => $paidOn->gt($due) ? (int) $due->diffInDays($paidOn) : 0,
                'legacy' => $paidOn->lt($cutoff),
            ];
        }

        $known = array_values(array_filter($outcomes, fn (array $o) => $o['on_time'] !== null));
        $windowStart = $asOf->copy()->subMonthsNoOverflow(24);
        $inWindow = array_filter($known, fn (array $o) => Carbon::parse($o['due_date'])->gte($windowStart));

        [$completed, $clean] = self::completedLoans($data, $outcomes);

        return new self(
            outcomes: $outcomes,
            maturedCount: count($outcomes),
            knownCount: count($known),
            onTimeCount: count(array_filter($known, fn (array $o) => $o['on_time'] === true)),
            lateCount: count(array_filter($known, fn (array $o) => $o['on_time'] === false)),
            legacyCount: count(array_filter($outcomes, fn (array $o) => $o['legacy'])),
            worstDaysLate24m: $inWindow === [] ? null : max(array_column($inWindow, 'days_late')),
            daysPastDueNow: $daysPastDueNow,
            completedLoans: $completed,
            cleanCompletedLoans: $clean,
            defaultedLoans: $data->loans->where('status', 'defaulted')->count(),
            writtenOffLoans: $data->loans->filter(fn (object $loan) => (float) $loan->write_off_amount > 0)->count(),
            restructuredLoans: $data->loans->where('status', 'restructured')->pluck('id')->map(fn ($id) => (int) $id)
                ->merge($data->inPlaceRestructuredLoanIds)->unique()->count(),
            everReleasedLoans: $data->loans->count(),
        );
    }

    public function hasHistory(): bool
    {
        return $this->maturedCount > 0;
    }

    /**
     * The date lateness is measured to on this loan: the as-of date, or a
     * restructured loan's restructure date (null when it has none).
     */
    private static function lateUntil(object $loan, Carbon $asOf): ?Carbon
    {
        if ($loan->status !== 'restructured') {
            return $asOf;
        }

        return $loan->restructured_at === null ? null : Carbon::parse($loan->restructured_at)->startOfDay()->min($asOf);
    }

    /**
     * The on-time rate in percent over periods whose outcome is known, or
     * null when none is.
     */
    public function onTimeRate(): ?float
    {
        return $this->knownCount === 0 ? null : $this->onTimeCount * 100 / $this->knownCount;
    }

    /**
     * The date the allocations completed the period's principal and
     * interest, or null when they never do.
     *
     * @param  iterable<int, object>  $allocations
     */
    private static function completedOn(iterable $allocations, int $owedCentavos): ?Carbon
    {
        $applied = 0;

        foreach ($allocations as $allocation) {
            $applied += Centavos::of($allocation->principal) + Centavos::of($allocation->interest);

            if ($applied >= $owedCentavos) {
                return Carbon::parse($allocation->payment_date)->startOfDay();
            }
        }

        return null;
    }

    /**
     * Completed loans, and those of them whose every matured period is known
     * and was never more than 7 days late.
     *
     * @param  list<array<string, mixed>>  $outcomes
     * @return array{0: int, 1: int}
     */
    private static function completedLoans(MemberCreditData $data, array $outcomes): array
    {
        $byLoan = collect($outcomes)->groupBy('loan_id');
        $completed = $data->loans->where('status', 'completed');

        $clean = $completed->filter(function (object $loan) use ($byLoan) {
            $periods = $byLoan->get((int) $loan->id);

            return $periods !== null
                && $periods->every(fn (array $o) => $o['days_late'] !== null && $o['days_late'] <= self::CLEAN_LOAN_MAX_DAYS_LATE);
        });

        return [$completed->count(), $clean->count()];
    }
}
