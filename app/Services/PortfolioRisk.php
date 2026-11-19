<?php

namespace App\Services;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Services\Accounting\LoanAgingBuilder;
use App\Services\Accounting\ReceivableAgingBuilder;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Portfolio quality, worked out once (Phase 6 decision 5): PAR 1, 30, 60 and
 * 90, and aging by loan.
 *
 * PAR n is the unpaid principal of the loans whose oldest unpaid period is
 * more than n days past its BARE due date (PAR 1: 1 day or more), over the
 * unpaid principal of the same set of loans. The whole unpaid principal of a
 * late loan counts, never just the late instalment, and grace is ignored:
 * PAR is a prudential ratio struck from the due date, the way the Loan Balance
 * Summary's `at_risk_amount` / `par_ratio` (PAR 30) and the daily snapshot's
 * PAR have always been struck. A loan is in PAR n exactly when its oldest
 * unpaid period is due before `as_of − n` days (`as_of` itself for PAR 1),
 * so PAR 30 here is that `at_risk_amount`, to the centavo.
 *
 * Aging by loan is LoanAgingBuilder's buckets (current, 1–30, 31–60, 61–90,
 * 91–120, over 120 days, by the loan's oldest unpaid period), with the loans
 * and their unpaid principal in each and each bucket's share of it. The
 * 91–120 / over 120 ladder is the allowance's (Accounting round C) and stays.
 *
 * The two rules part in two places, each kept as its report has always had it:
 * - Which loans: PAR measures whatever loans it is given (the Loan Balance
 *   Summary passes every ever-released loan, completed and restructured
 *   included); the aging measures collectible loans only.
 * - Which period dates a loan: PAR takes the oldest period still marked
 *   unpaid (pending, partial or overdue), even with nothing left owing on it;
 *   the aging takes the oldest such period with something left owing
 *   (AmortizationSchedule::remainingTotalSql() > 0).
 * On a book with neither (a completed or restructured loan's periods all
 * paid or closed, as they are), PAR 1 equals the aging's buckets past
 * current, loans and principal alike.
 *
 * Reads only, never writes, and takes the loans to measure as a query, so a
 * report passes its own statuses, branch scope, release dates and filters.
 */
final class PortfolioRisk
{
    /** Each PAR measure's days past due: a loan counts when it is MORE than this late (PAR 1: 1 day or more). */
    public const PAR_DAYS = ['par_1' => 0, 'par_30' => 30, 'par_60' => 60, 'par_90' => 90];

    /** Each aging bucket's label, in words. */
    public const BUCKET_LABELS = [
        'current' => 'Current',
        '1_30' => '1–30 days',
        '31_60' => '31–60 days',
        '61_90' => '61–90 days',
        '91_120' => '91–120 days',
        'over_120' => 'Over 120 days',
    ];

    public function __construct(private LoanAgingBuilder $aging) {}

    /**
     * PAR 1, 30, 60 and 90 over `$loans`, in one grouped query.
     *
     * Each measure is `amount` (pesos, rounded to the centavo), `ratio` (whole
     * percent to two places, 12.5 means 12.5%; 0 when nothing is owed) and
     * `loans` (how many). `portfolio_principal` is the denominator.
     *
     * @param  Builder<Loan>  $loans  its select, eager loads and order are ignored
     * @return array{portfolio_principal: float, par_1: array{amount: float, ratio: float, loans: int}, par_30: array{amount: float, ratio: float, loans: int}, par_60: array{amount: float, ratio: float, loans: int}, par_90: array{amount: float, ratio: float, loans: int}}
     */
    public function par(Builder $loans, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::today();
        $unpaid = implode(', ', array_fill(0, count(AmortizationSchedule::UNPAID_STATUSES), '?'));

        $perLoan = DB::table('amortization_schedules')
            ->whereIn('amortization_schedules.loan_id', $this->idsOf($loans))
            ->groupBy('amortization_schedules.loan_id')
            ->selectRaw(
                "MIN(CASE WHEN amortization_schedules.status IN ({$unpaid}) THEN amortization_schedules.due_date END) as oldest_unpaid,
                SUM(".AmortizationSchedule::remainingPrincipalSql().') as principal',
                AmortizationSchedule::UNPAID_STATUSES,
            );

        $select = ['COALESCE(SUM(per_loan.principal), 0) as portfolio_principal'];
        $bindings = [];
        foreach (self::PAR_DAYS as $key => $days) {
            $cutoff = $asOf->copy()->subDays($days)->toDateString();
            $select[] = "COALESCE(SUM(CASE WHEN per_loan.oldest_unpaid < ? THEN per_loan.principal ELSE 0 END), 0) as {$key}_amount";
            $select[] = "COUNT(CASE WHEN per_loan.oldest_unpaid < ? THEN 1 END) as {$key}_loans";
            array_push($bindings, $cutoff, $cutoff);
        }

        $row = DB::query()->fromSub($perLoan, 'per_loan')->selectRaw(implode(', ', $select), $bindings)->first();

        $portfolio = round((float) ($row->portfolio_principal ?? 0), 2);
        $result = ['portfolio_principal' => $portfolio];

        foreach (array_keys(self::PAR_DAYS) as $key) {
            $amount = (float) ($row->{"{$key}_amount"} ?? 0);

            $result[$key] = [
                'amount' => round($amount, 2),
                'ratio' => $portfolio > 0 ? round($amount / $portfolio * 100, 2) : 0.0,
                'loans' => (int) ($row->{"{$key}_loans"} ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Aging by loan over the collectible loans of `$loans`: every bucket, each
     * with `loans`, `principal` (pesos) and `share_percent` (its share of the
     * principal in all buckets, two places, null when nothing is owed), and
     * `totals`. The buckets' principal adds up to the gross loan portfolio
     * (PortfolioOutstanding's `outstanding_principal`) for the same loans.
     *
     * @param  Builder<Loan>  $loans  a starting query for LoanAgingBuilder (filters only)
     * @param  list<int>|null  $branchIds  null for every branch
     * @return array{as_of_date: string, buckets: list<array{bucket: string, label: string, loans: int, principal: float, share_percent: float|null}>, totals: array{loans: int, principal: float}}
     */
    public function agingByLoan(Builder $loans, ?array $branchIds, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::today();
        $totals = $this->aging->bucketTotals($asOf->toDateString(), $branchIds, $loans);

        $wholeCentavos = array_sum(array_column($totals, 'principal'));
        $buckets = [];

        foreach (ReceivableAgingBuilder::BUCKETS as $bucket) {
            $buckets[] = [
                'bucket' => $bucket,
                'label' => self::BUCKET_LABELS[$bucket],
                'loans' => $totals[$bucket]['loans'],
                'principal' => $totals[$bucket]['principal'] / 100,
                'share_percent' => self::sharePercent($totals[$bucket]['principal'], $wholeCentavos),
            ];
        }

        return [
            'as_of_date' => $asOf->toDateString(),
            'buckets' => $buckets,
            'totals' => [
                'loans' => array_sum(array_column($totals, 'loans')),
                'principal' => $wholeCentavos / 100,
            ],
        ];
    }

    /**
     * `$part` as a percentage of `$whole`, both in centavos, rounded half up to
     * two places in integers; null when the whole is 0. The same rule as
     * ReportService's shares.
     */
    private static function sharePercent(int $part, int $whole): ?float
    {
        if ($whole <= 0) {
            return null;
        }

        return intdiv($part * 10000 * 2 + $whole, $whole * 2) / 100;
    }

    /**
     * The ids of `$loans`, without the caller's columns, eager loads or order.
     *
     * @param  Builder<Loan>  $loans
     */
    private function idsOf(Builder $loans): QueryBuilder
    {
        $base = (clone $loans)->toBase();
        $base->columns = null;
        $base->bindings['select'] = [];

        return $base->reorder()->select('loans.id');
    }
}
