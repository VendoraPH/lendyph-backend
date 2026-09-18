<?php

namespace App\Services\Accounting;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use Illuminate\Support\Facades\DB;

/**
 * Receivable aging, as `Aging` in `src/types/accounting.ts`.
 *
 * The PHP half of `buildAging` in `@/lib/accounting/aging`, and the boundary
 * between the two halves of this application's money representation:
 *
 *   - LENDING tables store pesos as `decimal:2` — `amortization_schedules`
 *     carries `principal_due`, `penalty_amount` and the rest as decimals.
 *   - ACCOUNTING speaks integer CENTAVOS everywhere, because a trial balance
 *     has to prove `debit === credit` exactly.
 *
 * This report reads the lending tables and answers on an accounting screen, so
 * it crosses that boundary — and it is the ONLY place in this module that does.
 * The conversion is therefore explicit, single, and at the very edge: the SQL
 * sums in exact DECIMAL and {@see Money::toCentavos()} converts the result
 * once, from the string PDO returns rather than through a float. Nothing
 * downstream of `build()` is in pesos, and nothing upstream of it is in
 * centavos. A 100× error here would look entirely plausible on screen, which is
 * why AccountingReceivableAgingTest pins it against an
 * amount whose centavo and peso readings cannot be confused.
 *
 * ## What counts as a receivable
 *
 * An unpaid schedule row on a loan that can still owe money, carrying
 * principal + interest + penalty still outstanding — the same
 * {@see AmortizationSchedule::remainingTotalSql()} the Loan Balance Summary's
 * `outstanding` and `overdue` blocks use, so an overdue peso is counted the
 * same way on both screens. Each of those is floored at zero PER ROW, so an
 * overpayment on one period cannot net off what another period still owes.
 *
 * ## Grace is deliberately ignored
 *
 * Measured from the bare due date, exactly like `ReportService::agingReport()`
 * and `daysPastDue()` on the frontend. Aging buckets are a prudential
 * portfolio-quality measure and are conventionally struck from the due date;
 * honouring each product's grace period would shift every boundary and move
 * reported portfolio quality. That is a provisioning decision, not an
 * engineering one, so it is recorded here rather than made. If this is ever
 * changed, `ReportService::agingReport()` has to change with it — two aging
 * reports disagreeing about what "31 days late" means is worse than either
 * convention.
 */
final class ReceivableAgingBuilder
{
    /**
     * Buckets in report order, youngest first — the exact list and order of
     * `AGING_BUCKETS` in `@/lib/accounting/aging`.
     *
     * Every one is returned even when empty, so the table has a stable shape
     * and a row does not disappear the month nothing lands in it.
     */
    public const BUCKETS = ['current', '1_30', '31_60', '61_90', '91_120', 'over_120'];

    /** The bucket for anything not yet due. Excluded from `past_due_total`. */
    private const CURRENT = 'current';

    /**
     * The aged report as of a date.
     *
     * @return array{as_of: string, rows: list<array{bucket: string, amount: int, count: int}>, total: int, past_due_total: int}
     */
    public function build(string $asOf, ?int $branchId = null): array
    {
        $totals = $this->bucketTotals($asOf, $branchId);

        $rows = [];
        foreach (self::BUCKETS as $bucket) {
            $rows[] = [
                'bucket' => $bucket,
                'amount' => $totals[$bucket]['amount'] ?? 0,
                'count' => $totals[$bucket]['count'] ?? 0,
            ];
        }

        $total = Money::sum(array_column($rows, 'amount'));

        return [
            'as_of' => $asOf,
            'rows' => $rows,
            'total' => $total,
            // Everything except `current`. Being early is not a degree of
            // lateness, so it is subtracted rather than bucketed.
            'past_due_total' => $total - ($totals[self::CURRENT]['amount'] ?? 0),
        ];
    }

    /**
     * One grouped query: amount and count per bucket, in centavos.
     *
     * Grouped in SQL rather than fetched and bucketed in PHP. A co-op's open
     * schedule rows run to tens of thousands and the report needs six numbers
     * out of them; pulling every row across the wire to add it up in a loop
     * would be the difference between a report and a timeout.
     *
     * @return array<string, array{amount: int, count: int}>
     */
    private function bucketTotals(string $asOf, ?int $branchId): array
    {
        $remaining = AmortizationSchedule::remainingTotalSql();

        $rows = DB::table('amortization_schedules')
            ->join('loans', 'loans.id', '=', 'amortization_schedules.loan_id')
            ->whereIn('amortization_schedules.status', AmortizationSchedule::UNPAID_STATUSES)
            // `defaulted` is in this set and must be: a defaulted loan still
            // owes, and an aging report that dropped it would understate exactly
            // the receivables a provisioning policy cares most about.
            ->whereIn('loans.status', Loan::COLLECTIBLE_STATUSES)
            ->when($branchId !== null, fn ($q) => $q->where('loans.branch_id', $branchId))
            // A zero-balance row is not a receivable. Skipped rather than
            // bucketed at zero, so it cannot inflate `count` with finished
            // business — `buildAging` skips the same rows for the same reason,
            // and the counts have to agree.
            ->whereRaw("{$remaining} > 0")
            ->groupBy('bucket')
            ->selectRaw(
                $this->bucketCase().' as bucket, '
                // SUM over DECIMAL(,2) is exact decimal arithmetic in MySQL and
                // comes back as a STRING through PDO. Left as a string all the
                // way to Money::toCentavos() below, which parses digit by digit
                // — casting to float here is what would silently cost the last
                // centavo on a large portfolio.
                ."COALESCE(SUM({$remaining}), 0) as amount, "
                // Schedule ROWS, not distinct loans, and the difference is
                // load-bearing. `buildAging` counts one item per outstanding
                // balance, and the books screen's footer SUMS the count column
                // into a total — so the counts have to be additive across
                // buckets. Counting distinct loans (which is what
                // ReportService::agingReport() correctly does for a
                // delinquency figure) would double-count a loan with late
                // instalments in two buckets and make that footer wrong.
                .'COUNT(*) as count',
                array_fill(0, 5, $asOf),
            )
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $amount = Money::toCentavos((string) $row->amount);

            // Money::toCentavos() answers null for anything it will not vouch
            // for — a negative, or a figure past its ceiling. GREATEST() floors
            // every term at zero so a negative cannot arise, which makes null
            // here a real anomaly rather than an empty bucket. Defaulting it to
            // 0 would report a portfolio as collected; failing loudly is the
            // only honest option on a figure a provision is struck from.
            if ($amount === null) {
                throw new \RuntimeException(
                    "Receivable aging: bucket '{$row->bucket}' summed to '{$row->amount}', "
                    .'which is not a convertible peso amount.'
                );
            }

            $totals[(string) $row->bucket] = [
                'amount' => $amount,
                'count' => (int) $row->count,
            ];
        }

        return $totals;
    }

    /**
     * Days-past-due bucketing, as a SQL CASE. The port of
     * `bucketForDaysPastDue`.
     *
     * Upper bounds are INCLUSIVE — 30 days is the last day of "1–30", 31 the
     * first of "31–60" — so no schedule belongs to two buckets or to none. The
     * old lending query anchored on `subDays($minDays - 1)` and counted
     * anything due exactly 30 / 60 / 90 days ago in two buckets at once, so the
     * buckets never summed to the total. Written as a single CASE rather than
     * one query per bucket precisely so that class of overlap is impossible by
     * construction: a row matches exactly one arm.
     *
     * Read against `daysPastDue()` on the frontend, which is
     * `floor((asOf - due_date) / 1 day)` floored at zero:
     *
     *   due_date >= asOf            ->  0 days or negative  ->  current
     *   due_date >= asOf - 30 days  ->  1..30               ->  1_30
     *   due_date >= asOf - 60 days  ->  31..60              ->  31_60
     *   due_date >= asOf - 90 days  ->  61..90              ->  61_90
     *   due_date >= asOf - 120 days ->  91..120             ->  91_120
     *   otherwise                   ->  121+                ->  over_120
     *
     * `due_date` is left BARE on the left of every comparison, with the
     * arithmetic on the right where `?` is a constant. Wrapping the column in
     * DATEDIFF() instead would read identically and forfeit the
     * (loan_id, status, due_date) index on every row — and this is the heaviest
     * scan in the accounting module.
     *
     * Takes five bindings, all the as-of date.
     */
    private function bucketCase(): string
    {
        return <<<'SQL'
            CASE
                WHEN `amortization_schedules`.`due_date` >= ? THEN 'current'
                WHEN `amortization_schedules`.`due_date` >= DATE_SUB(?, INTERVAL 30 DAY) THEN '1_30'
                WHEN `amortization_schedules`.`due_date` >= DATE_SUB(?, INTERVAL 60 DAY) THEN '31_60'
                WHEN `amortization_schedules`.`due_date` >= DATE_SUB(?, INTERVAL 90 DAY) THEN '61_90'
                WHEN `amortization_schedules`.`due_date` >= DATE_SUB(?, INTERVAL 120 DAY) THEN '91_120'
                ELSE 'over_120'
            END
            SQL;
    }
}
