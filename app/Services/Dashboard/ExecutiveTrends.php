<?php

namespace App\Services\Dashboard;

use App\Services\ReportService;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The executive dashboard's monthly trends (Phase 6 EB-D2), each month read
 * from the reports' own services for that month, never added up here:
 *
 * - Chart B: `principal_released` (Cash Flow's `non_cash.principal_released`,
 *   the Loans Released card's figure, restructures included) against
 *   `principal_collected` (Cash Flow's `inflows.repayments.principal`);
 * - Chart I: `cash_in`, `cash_out` and `net_cash` (Cash Flow's
 *   `inflows.total`, `outflows.total` and `net_movement`: loan cash plus share
 *   capital paid in and out on its own);
 * - Chart L: `new_members` (the Member Report's `new`).
 *
 * The months are the calendar months the period touches, and at least the
 * TREND_MONTHS ending with its last month, so a short period still shows a
 * trend. A month is whole, except that the last ends on the period's end and,
 * when the period itself reaches back to the first month, the first starts on
 * the period's first day. More than MAX_MONTHS is refused.
 *
 * The filters reach the reports as they are; LoanScope::applied() with
 * self::FILTERS_APPLIED_TO says which figures follow which.
 */
final class ExecutiveTrends
{
    public const TREND_MONTHS = 12;

    public const MAX_MONTHS = 36;

    /**
     * The trend figures each dashboard filter narrows: Cash Flow's loan cash
     * follows product and officer, its receipts the collector, and share
     * capital and new members none of them (MetricCatalog::REPORTS).
     *
     * @var array<string, list<string>>
     */
    public const FILTERS_APPLIED_TO = [
        'product_id' => ['principal_released', 'principal_collected', 'cash_in', 'cash_out', 'net_cash'],
        'officer_id' => ['principal_released', 'principal_collected', 'cash_in', 'cash_out', 'net_cash'],
        'collector_id' => ['principal_collected', 'cash_in', 'net_cash'],
    ];

    public function __construct(private ReportService $reports) {}

    /**
     * @param  array<string, mixed>  $filters  report filters (branch_ids, branch_id, product_id, officer_id, collector_id)
     * @return list<array{month: string, from: string, to: string, principal_released: float, principal_collected: float, cash_in: float, cash_out: float, net_cash: float, new_members: int}>
     *
     * @throws InvalidArgumentException in words, for a period of more than MAX_MONTHS
     */
    public function months(DashboardPeriod $period, array $filters): array
    {
        $trend = [];

        foreach (self::ranges($period) as [$from, $to]) {
            $monthFilters = [...$filters, 'date_from' => $from->toDateString(), 'date_to' => $to->toDateString()];
            $cashFlow = $this->reports->cashFlow($monthFilters);

            $trend[] = [
                'month' => $from->format('Y-m'),
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'principal_released' => $cashFlow['non_cash']['principal_released'],
                'principal_collected' => $cashFlow['inflows']['repayments']['principal'],
                'cash_in' => $cashFlow['inflows']['total'],
                'cash_out' => $cashFlow['outflows']['total'],
                'net_cash' => $cashFlow['net_movement'],
                'new_members' => $this->reports->memberCounts($monthFilters)['new'],
            ];
        }

        return $trend;
    }

    /**
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private static function ranges(DashboardPeriod $period): array
    {
        $lastMonth = $period->to->startOfMonth();
        $earliest = $lastMonth->subMonthsNoOverflow(self::TREND_MONTHS - 1);
        $firstMonth = $period->from->startOfMonth()->lt($earliest) ? $period->from->startOfMonth() : $earliest;
        $count = ($lastMonth->year - $firstMonth->year) * 12 + $lastMonth->month - $firstMonth->month + 1;

        if ($count > self::MAX_MONTHS) {
            throw new InvalidArgumentException('Trends cover at most '.self::MAX_MONTHS.' months. Pick a shorter period.');
        }

        $ranges = [];
        for ($offset = 0; $offset < $count; $offset++) {
            $month = $firstMonth->addMonthsNoOverflow($offset);
            $from = $offset === 0 && $month->eq($period->from->startOfMonth()) ? $period->from : $month;
            $to = $offset === $count - 1 ? $period->to : $month->endOfMonth()->startOfDay();
            $ranges[] = [$from, $to];
        }

        return $ranges;
    }
}
