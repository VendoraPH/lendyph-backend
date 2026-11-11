<?php

namespace App\Services\Cda\Sources;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\PortfolioDailySnapshot;
use App\Services\Cda\Reports\Rational;
use App\Services\LoanListTotals;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Loan figures for CDA reports (design §2), each from the module that shows
 * it on screen, so the two always agree:
 *
 * - `loans.released`: the Releases List (ReportService::releasesQuery), by
 *   release date, optionally one product.
 * - `loans.outstanding`, `loans.past_due`, `loans.par`: balances can't be
 *   read for a past day, so they come, in order, from the year-end capture
 *   (for the period's Dec 31), the daily portfolio snapshot for that day
 *   (`portfolio:snapshot`), or the current balances (LoanListTotals, the
 *   Portfolio Summary's rule), marked provisional with a note when the day
 *   has passed. Past due is the grace rule; PAR n is the principal of loans
 *   whose oldest unpaid period is more than n days late (1: any day), over
 *   the outstanding principal, from the bare due date.
 * - `loans.by_product`: Portfolio by Product, by release date.
 * - `loans.collection_efficiency`: the Collection Efficiency report's rate.
 *
 * The classification and provisioning keys are the `classification` family
 * (C4). Every figure covers the whole cooperative.
 */
final class LoansSource implements CdaSource
{
    public const LIVE_NOTE = 'Computed from current balances: no year-end capture or daily snapshot covers this date.';

    public const COUNT_NOTE = 'The daily snapshot keeps no loan count, and loans keep no completion date, so this counts the loans released by then that are still collectible today.';

    /** PAR n's lateness threshold, in days past due: 1 means any lateness. */
    private const PAR_DAYS_PAST = [1 => 0, 30 => 30, 90 => 90];

    public function __construct(
        private readonly ReportService $reports,
        private readonly LoanListTotals $listTotals,
    ) {}

    public function resolve(string $key, array $params, SourceContext $context): SourceResult
    {
        return match ($key) {
            'loans.released' => $this->released($params, $context),
            'loans.outstanding' => $this->outstanding($params, $context),
            'loans.past_due' => $this->pastDue($params, $context),
            'loans.par' => $this->par($params, $context),
            'loans.by_product' => $this->byProduct($params, $context),
            'loans.collection_efficiency' => $this->collectionEfficiency($params, $context),
            default => throw SourceUnavailable::unknownKey($key, 'loans'),
        };
    }

    private function released(array $params, SourceContext $context): SourceResult
    {
        $from = $context->date($params['from']);
        $to = $context->date($params['to']);
        $query = $this->reports->releasesQuery(['branch_ids' => null, 'date_from' => $from, 'date_to' => $to])->reorder()
            ->when($params['product'] ?? null, fn ($q, int $product) => $q->where('loan_product_id', $product));
        $label = SourceResult::labelFor('Releases List'.(isset($params['product']) ? ' (one product)' : ''), $to);

        if ($params['measure'] === 'count') {
            return new SourceResult($query->count(), $label, 'count', $to, MembersSource::notYetEnded($to));
        }

        return new SourceResult(self::money($query->sum('principal_amount')), $label, 'money', $to, MembersSource::notYetEnded($to));
    }

    private function outstanding(array $params, SourceContext $context): SourceResult
    {
        $asOf = $context->date($params['as_of']);
        $figures = $this->figures($asOf, $context);
        $value = match ($params['measure']) {
            'count' => $figures['loan_count'],
            'total' => Rational::fromCentavos($figures['total_outstanding'])->toFixed(2),
            default => Rational::fromCentavos($figures['outstanding_principal'])->toFixed(2),
        };
        $what = $params['measure'] === 'total' ? 'Loans outstanding (everything owed)' : 'Loans outstanding (principal)';

        if ($params['measure'] === 'count' && $figures['basis'] === 'snapshot') {
            // Loans carry no completion date, so the count can't be rebuilt for a past day.
            return new SourceResult($value, SourceResult::labelFor('Loans outstanding (daily snapshot)', $asOf), 'count', $asOf, true, ['basis' => 'snapshot', 'note' => self::COUNT_NOTE]);
        }

        return $this->result($value, $what, $params['measure'] === 'count' ? 'count' : 'money', $asOf, $figures);
    }

    private function pastDue(array $params, SourceContext $context): SourceResult
    {
        $asOf = $context->date($params['as_of']);
        $figures = $this->figures($asOf, $context);

        return $params['measure'] === 'count'
            ? $this->result($figures['past_due_count'], 'Past due loans', 'count', $asOf, $figures)
            : $this->result(Rational::fromCentavos($figures['past_due_amount'])->toFixed(2), 'Past due amount', 'money', $asOf, $figures);
    }

    private function par(array $params, SourceContext $context): SourceResult
    {
        $asOf = $context->date($params['as_of']);
        $days = (int) $params['days'];
        $figures = $this->figures($asOf, $context);
        $atRisk = $figures['par'][$days];
        $ratio = Rational::fromCentavos($atRisk)->divide(Rational::fromCentavos($figures['outstanding_principal']));

        return $this->result($ratio?->multiply(Rational::of(100))->toFixed(2), "PAR {$days}", 'percent', $asOf, $figures);
    }

    private function byProduct(array $params, SourceContext $context): SourceResult
    {
        $from = $context->date($params['from']);
        $to = $context->date($params['to']);
        $products = $this->reports->portfolioByProduct(['branch_ids' => null, 'date_from' => $from, 'date_to' => $to])['products'];

        $rows = array_map(fn (array $product) => [
            'product' => (string) $product['product_name'],
            'count' => (int) $product['loan_count'],
            'amount' => self::money($product['total_released']),
            'outstanding_principal' => self::money($product['outstanding_principal']),
        ], $products);

        return new SourceResult($rows, SourceResult::labelFor('Portfolio by Product', $to), 'table', $to, MembersSource::notYetEnded($to));
    }

    private function collectionEfficiency(array $params, SourceContext $context): SourceResult
    {
        $from = $context->date($params['from']);
        $to = $context->date($params['to']);
        $report = $this->reports->collectionEfficiency(['branch_ids' => null, 'date_from' => $from, 'date_to' => $to]);

        return new SourceResult(
            self::money($report['collection_rate']),
            SourceResult::labelFor('Collection Efficiency', $to),
            'percent',
            $to,
            MembersSource::notYetEnded($to),
        );
    }

    /**
     * The day's loan figures in centavos, from the best record of that day.
     *
     * @return array{basis: string, loan_count: int, outstanding_principal: int, total_outstanding: int, past_due_amount: int, past_due_count: int, par: array<int, int>, provisional: bool}
     */
    private function figures(string $asOf, SourceContext $context): array
    {
        return $context->remember('loans.figures|'.$asOf, fn () => new SourceResult($this->readFigures($asOf, $context), '', 'any'))->value;
    }

    private function readFigures(string $asOf, SourceContext $context): array
    {
        $capture = $context->period->year_end_capture;

        if ($asOf === $context->period->period_end->toDateString() && is_array($capture) && isset($capture['loans'])) {
            return $this->fromCapture($capture);
        }

        $snapshot = PortfolioDailySnapshot::query()->whereDate('snapshot_date', $asOf)->first();

        if ($snapshot !== null && $asOf < now()->toDateString()) {
            return [
                'basis' => 'snapshot',
                'loan_count' => Loan::query()->whereIn('status', Loan::COLLECTIBLE_STATUSES)->where('released_at', '<', Carbon::parse($asOf)->addDay()->toDateString())->count(),
                'outstanding_principal' => Rational::of((string) $snapshot->outstanding_principal)->toCentavos(),
                'total_outstanding' => Rational::of((string) $snapshot->total_outstanding)->toCentavos(),
                'past_due_amount' => Rational::of((string) $snapshot->overdue_amount)->toCentavos(),
                'past_due_count' => (int) $snapshot->overdue_loans_count,
                'par' => [
                    1 => Rational::of((string) $snapshot->par_1_amount)->toCentavos(),
                    30 => Rational::of((string) $snapshot->par_30_amount)->toCentavos(),
                    90 => Rational::of((string) $snapshot->par_90_amount)->toCentavos(),
                ],
                'provisional' => false,
            ];
        }

        return $this->live($asOf);
    }

    /** @param  array<string, mixed>  $capture */
    private function fromCapture(array $capture): array
    {
        $loans = collect($capture['loans']);
        $par = [];

        foreach (self::PAR_DAYS_PAST as $days => $threshold) {
            $par[$days] = (int) $loans->filter(fn (array $loan) => (int) ($loan['days_past_due'] ?? 0) > $threshold)->sum('outstanding_principal');
        }

        return [
            'basis' => 'capture',
            'loan_count' => $loans->count(),
            'outstanding_principal' => (int) $loans->sum('outstanding_principal'),
            'total_outstanding' => (int) $loans->sum('total_outstanding'),
            'past_due_amount' => (int) $loans->sum('past_due_amount'),
            'past_due_count' => $loans->filter(fn (array $loan) => (bool) ($loan['is_past_due'] ?? false))->count(),
            'par' => $par,
            'provisional' => false,
        ];
    }

    /** Today's balances: the Portfolio Summary's figures and the snapshot command's PAR rule. */
    private function live(string $asOf): array
    {
        $today = Carbon::today();
        $collectible = Loan::query()->whereIn('loans.status', Loan::COLLECTIBLE_STATUSES);
        $totals = $this->listTotals->for(clone $collectible, $today);

        return [
            'basis' => $asOf < $today->toDateString() ? 'live_past' : 'live',
            'loan_count' => (clone $collectible)->count(),
            'outstanding_principal' => Rational::of($totals['outstanding_principal'])->toCentavos(),
            'total_outstanding' => Rational::of($totals['total_outstanding'])->toCentavos(),
            'past_due_amount' => Rational::of($totals['overdue_amount'])->toCentavos(),
            'past_due_count' => $totals['overdue_count'],
            'par' => $this->livePar($today),
            'provisional' => true,
        ];
    }

    /**
     * PAR 1/30/90 principal today, as SnapshotPortfolio::parAmounts() strikes
     * it: per collectible loan its oldest unpaid due date and unpaid principal.
     *
     * @return array<int, int>
     */
    private function livePar(Carbon $today): array
    {
        $unpaid = implode(', ', array_fill(0, count(AmortizationSchedule::UNPAID_STATUSES), '?'));
        $perLoan = DB::table('amortization_schedules')
            ->join('loans', 'loans.id', '=', 'amortization_schedules.loan_id')
            ->whereIn('loans.status', Loan::COLLECTIBLE_STATUSES)
            ->groupBy('amortization_schedules.loan_id')
            ->selectRaw(
                "MIN(CASE WHEN amortization_schedules.status IN ({$unpaid}) THEN amortization_schedules.due_date END) as oldest_unpaid,
                SUM(".AmortizationSchedule::remainingPrincipalSql().') as principal',
                AmortizationSchedule::UNPAID_STATUSES,
            );

        $select = [];
        $bindings = [];

        foreach (self::PAR_DAYS_PAST as $days => $threshold) {
            $select[] = "COALESCE(SUM(CASE WHEN per_loan.oldest_unpaid < ? THEN per_loan.principal ELSE 0 END), 0) as par_{$days}";
            $bindings[] = $today->copy()->subDays($threshold)->toDateString();
        }

        $row = DB::query()->fromSub($perLoan, 'per_loan')->selectRaw(implode(', ', $select), $bindings)->first();

        return array_map(
            fn (int $days) => Rational::of(number_format((float) ($row->{"par_{$days}"} ?? 0), 2, '.', ''))->toCentavos(),
            array_combine(array_keys(self::PAR_DAYS_PAST), array_keys(self::PAR_DAYS_PAST)),
        );
    }

    private function result(mixed $value, string $what, string $unit, string $asOf, array $figures): SourceResult
    {
        $basis = match ($figures['basis']) {
            'capture' => ' (year-end capture)',
            'snapshot' => ' (daily snapshot)',
            default => '',
        };
        $past = $figures['basis'] === 'live_past';

        return new SourceResult(
            $value,
            SourceResult::labelFor($what.$basis, $asOf),
            $unit,
            $asOf,
            $figures['provisional'],
            $past ? ['basis' => 'current_balances', 'note' => self::LIVE_NOTE] : ['basis' => $figures['basis']],
        );
    }

    private static function money(int|float|string|null $pesos): string
    {
        return Rational::of(is_float($pesos) ? number_format($pesos, 2, '.', '') : (string) ($pesos ?? 0))->toFixed(2);
    }
}
