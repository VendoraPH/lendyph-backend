<?php

namespace App\Services\Dashboard;

use App\Models\Loan;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Application turnaround (Phase 6 EB-D2, ask 5): how long loans took from
 * submitted to approved, approved to released, and submitted to released.
 * Its own class so loan origination (LO-B3) reads the same figures.
 *
 * - Submitted is Loan::submittedAt(true): the submit step of the first
 *   approval round, or else the audit row it falls back to. Approved and
 *   released are `approved_at` and `released_at`.
 * - Each loan counts in the period its stretch's last step falls in:
 *   approval for submitted → approved, release for the other two.
 * - A loan missing the other end (an imported loan, or one approved before
 *   its approval was recorded) counts as `not_measured`, never estimated. So
 *   does a stretch that would run backwards.
 * - Days are elapsed time ÷ 24 hours. The average and the median (the mean of
 *   the two middle loans when the count is even) are rounded half up to one
 *   decimal; with no loan measured both read null.
 * - Branch, product and officer narrow the loans; the collector does not.
 * - `$narrow` narrows the loans further for a caller of its own: loan
 *   origination passes the loans its online applications became (LO-B3).
 *
 * Reads only.
 */
final class ApplicationTurnaround
{
    /** The filters the figures follow (LoanScope::applied()). */
    public const FILTERS = ['branch_id', 'product_id', 'officer_id'];

    private const STRETCHES = [
        'submitted_to_approved' => ['submitted', 'approved_at'],
        'approved_to_released' => ['approved_at', 'released_at'],
        'submitted_to_released' => ['submitted', 'released_at'],
    ];

    /**
     * @param  list<int>|null  $branchIds  null for every branch
     * @param  (Closure(Builder<Loan>): mixed)|null  $narrow  an extra loan filter
     * @return array{submitted_to_approved: array{loans: int, average_days: float|null, median_days: float|null, not_measured: int}, approved_to_released: array{loans: int, average_days: float|null, median_days: float|null, not_measured: int}, submitted_to_released: array{loans: int, average_days: float|null, median_days: float|null, not_measured: int}}
     */
    public function build(string $from, string $to, ?array $branchIds, LoanScope $scope, ?Closure $narrow = null): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->addDay()->startOfDay();

        $loans = Loan::query()
            ->with('approvalSteps')
            ->when($branchIds !== null, fn (Builder $q) => $q->whereIn('loans.branch_id', $branchIds))
            ->tap($scope->loans())
            ->when($narrow !== null, fn (Builder $q) => $q->tap($narrow))
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $approved) => $approved->where('approved_at', '>=', $start)->where('approved_at', '<', $end))
                ->orWhere(fn (Builder $released) => $released->where('released_at', '>=', $start)->where('released_at', '<', $end)))
            ->orderBy('id')
            ->get();

        $figures = [];

        foreach (self::STRETCHES as $stretch => [$first, $last]) {
            $days = [];
            $notMeasured = 0;

            foreach ($loans as $loan) {
                $lastAt = $loan->{$last};

                if ($lastAt === null || $lastAt->lt($start) || $lastAt->gte($end)) {
                    continue;
                }

                $firstAt = $first === 'submitted' ? $loan->submittedAt(true) : $loan->{$first};

                if ($firstAt === null || $firstAt->gt($lastAt)) {
                    $notMeasured++;

                    continue;
                }

                $days[] = self::days($firstAt, $lastAt);
            }

            $figures[$stretch] = [
                'loans' => count($days),
                'average_days' => $days === [] ? null : self::oneDecimal(array_sum($days) / count($days)),
                'median_days' => $days === [] ? null : self::oneDecimal(self::median($days)),
                'not_measured' => $notMeasured,
            ];
        }

        return $figures;
    }

    private static function days(CarbonInterface $from, CarbonInterface $to): float
    {
        return ($to->getTimestamp() - $from->getTimestamp()) / 86400;
    }

    /** @param  non-empty-list<float>  $values */
    private static function median(array $values): float
    {
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    private static function oneDecimal(float $days): float
    {
        return round($days, 1, PHP_ROUND_HALF_UP);
    }
}
