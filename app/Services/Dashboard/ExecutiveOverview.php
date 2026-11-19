<?php

namespace App\Services\Dashboard;

use App\Models\AccountingAccount;
use App\Models\User;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\IncomeStatementBuilder;
use App\Services\Accounting\YearEndCloser;
use App\Services\ReportService;
use Illuminate\Support\Facades\Cache;

/**
 * The executive dashboard's cards (Phase 6 EB-D1): the spec's 12 KPIs plus
 * #614's Outstanding and Required Allowance. Every figure is its report's own,
 * called through that report's service with the same period, branches and
 * filters, so a card always equals its report:
 *
 * - balances (as of the period's end): the Portfolio Summary
 *   (ReportService::loanBalanceSummary(), PAR through PortfolioRisk, never
 *   worked out here), the Provisioning report and the Member Report;
 * - period figures: Cash Flow (Releases as principal, Collections without the
 *   insurance premium, Collected Insurance), Collection Efficiency and the
 *   Share Capital report;
 * - the books: the income statement (IncomeStatementBuilder), by journal
 *   branch, cash basis.
 *
 * A balance for a past day reads the last daily snapshot on or before it
 * (PortfolioHistory, "No backfill"); none reads `no_snapshot`. The previous
 * value is the previous period's figure (period cards) or the balance at the
 * previous period's end (balance cards). `change` is a percentage, or points
 * for a ratio, and null when it can't be worked out (no previous value, or a
 * previous value of zero).
 *
 * Cards are cached per branch scope, filters, period and day for
 * self::CACHE_SECONDS; each caller's permissions are applied after the cache,
 * so a cached figure never reaches a caller missing its source's permission
 * (`no_access`).
 */
final class ExecutiveOverview
{
    public const CACHE_SECONDS = 300;

    public const CARDS = [
        'gross_loan_portfolio', 'total_outstanding', 'active_loans', 'loans_released', 'collections',
        'past_due_amount', 'par_30', 'active_members', 'collection_efficiency', 'interest_income',
        'net_income', 'share_capital', 'collected_insurance', 'required_allowance',
    ];

    /** The balance cards history can give a past value for: card => PortfolioHistory figure. */
    private const HISTORY_FIGURES = [
        'gross_loan_portfolio' => 'outstanding_principal',
        'total_outstanding' => 'total_outstanding',
        'active_loans' => 'active_loans',
        'past_due_amount' => 'past_due_amount',
        'par_30' => 'par_30',
    ];

    public function __construct(
        private ReportService $reports,
        private PortfolioHistory $history,
        private IncomeStatementBuilder $incomeStatement,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  ExecutiveDashboardRequest::reportFilters()
     * @param  list<int>|null  $scope  the branches read (ExecutiveDashboardRequest::branchScope())
     * @return array{period: array<string, mixed>, filters_applied: list<string>, cards: array<string, array<string, mixed>>}
     */
    public function build(User $user, DashboardPeriod $period, array $filters, ?array $scope): array
    {
        $key = 'dashboard:executive:overview:v1:'.sha1((string) json_encode([
            DashboardPeriod::today()->toDateString(),
            $period->toArray(),
            $scope,
            $filters['product_id'] ?? null,
            $filters['officer_id'] ?? null,
            $filters['collector_id'] ?? null,
        ]));

        $cards = Cache::remember($key, self::CACHE_SECONDS, fn (): array => $this->cards($period, $filters, $scope));

        return [
            'period' => $period->toArray(),
            'filters_applied' => LoanScope::fromFilters($filters)->applied(['product_id', 'officer_id', 'collector_id'])['filters_applied'],
            'cards' => array_map(fn (array $card): array => self::forCaller($card, $user), $cards),
        ];
    }

    /**
     * A card as the caller may see it: `no_access`, with no figures, when any
     * of its source's permissions is missing.
     *
     * @param  array<string, mixed>  $card
     * @return array<string, mixed>
     */
    private static function forCaller(array $card, User $user): array
    {
        $permissions = MetricCatalog::all()[$card['key']]['permissions'];

        foreach ($permissions as $permission) {
            if (! $user->can($permission)) {
                return [
                    ...$card,
                    'value' => null,
                    'previous_value' => null,
                    'change' => null,
                    'state' => 'no_access',
                    'details' => [],
                ];
            }
        }

        return $card;
    }

    /**
     * Every card, worked out with every source (no caller's permissions).
     *
     * @param  array<string, mixed>  $filters
     * @param  list<int>|null  $scope
     * @return array<string, array<string, mixed>>
     */
    private function cards(DashboardPeriod $period, array $filters, ?array $scope): array
    {
        $today = DashboardPeriod::today()->toDateString();
        $to = $period->to->toDateString();
        $previousTo = $period->previousTo->toDateString();
        $isLive = $to === $today;
        $current = ['date_from' => $period->from->toDateString(), 'date_to' => $to];
        $previous = ['date_from' => $period->previousFrom->toDateString(), 'date_to' => $previousTo];

        $summary = $isLive ? $this->reports->loanBalanceSummary($filters) : null;
        $flow = $this->reports->cashFlow([...$filters, ...$current]);
        $previousFlow = $this->reports->cashFlow([...$filters, ...$previous]);
        $efficiency = $this->reports->collectionEfficiency([...$filters, ...$current]);
        $previousEfficiency = $this->reports->collectionEfficiency([...$filters, ...$previous]);
        $members = $this->reports->borrowerReport([...$filters, ...$current]);
        $previousMembers = $this->reports->borrowerReport([...$filters, ...$previous]);
        $shareCapital = $this->reports->shareCapital([...$filters, ...$current]);
        $allowance = $isLive ? $this->reports->provisioning($filters) : null;

        // History follows the branch and product only (2026-11-17 decision
        // 17), so with an officer or collector filter it has nothing to give.
        $historyUsable = blank($filters['officer_id'] ?? null) && blank($filters['collector_id'] ?? null);
        $points = $historyUsable
            ? $this->history->points(array_values(array_unique([$to, $previousTo])), $scope, filled($filters['product_id'] ?? null) ? (int) $filters['product_id'] : null)['points']
            : [];
        $pointOn = fn (string $date): ?array => collect($points)->firstWhere('requested_date', $date);

        $balance = function (string $card, mixed $liveValue, array $details = []) use ($isLive, $pointOn, $to, $previousTo): array {
            $figure = self::HISTORY_FIGURES[$card];
            $read = function (?array $point) use ($figure, $card): mixed {
                $value = $point['figures'][$figure] ?? null;

                return $card === 'par_30' ? ($value['ratio'] ?? null) : $value;
            };

            $then = $pointOn($previousTo);
            $now = $isLive ? null : $pointOn($to);
            $value = $isLive ? $liveValue : $read($now);
            $state = $isLive || ($now['state'] ?? null) === 'ready' ? 'ready' : 'no_snapshot';

            return $this->card($card, $state === 'ready' ? $value : null, $read($then), $isLive ? $to : ($now['snapshot_date'] ?? $to), $then['snapshot_date'] ?? $previousTo, $state, $isLive ? $details : []);
        };

        $repayments = fn (array $cashFlow): array => $cashFlow['inflows']['repayments'];
        $collected = fn (array $cashFlow): float => round($repayments($cashFlow)['total'] - $repayments($cashFlow)['insurance'], 2);

        $cards = [
            $balance('gross_loan_portfolio', $summary['outstanding_principal'] ?? null),
            $balance('total_outstanding', $summary['total_outstanding'] ?? null),
            $balance('active_loans', $summary['active_loans'] ?? null),
            $this->card('loans_released', $flow['non_cash']['principal_released'], $previousFlow['non_cash']['principal_released'], $to, $previousTo, 'ready', [
                'count' => $flow['outflows']['releases']['count'],
            ]),
            $this->card('collections', $collected($flow), $collected($previousFlow), $to, $previousTo, 'ready', [
                'principal' => $repayments($flow)['principal'],
                'interest' => $repayments($flow)['interest'],
                'penalty' => $repayments($flow)['penalty'],
                'build_up_and_overpayment' => $repayments($flow)['overpayment'],
                'count' => $repayments($flow)['count'],
            ]),
            $balance('past_due_amount', $summary['past_due_amount'] ?? null, ['loans' => $summary['past_due_loan_count'] ?? null]),
            $balance('par_30', $summary['par_30']['ratio'] ?? null, $summary === null ? [] : [
                'par_1' => $summary['par_1'],
                'par_30' => $summary['par_30'],
                'par_60' => $summary['par_60'],
                'par_90' => $summary['par_90'],
                'portfolio_principal' => $summary['par_portfolio_principal'],
            ]),
            $this->card('active_members', $members['total_active_borrowers'], $previousMembers['total_active_borrowers'], $to, $previousTo, 'ready'),
            $this->card('collection_efficiency', $efficiency['collection_rate'], $previousEfficiency['collection_rate'], $to, $previousTo, 'ready', [
                'total_due' => $efficiency['total_due'],
                'total_collected' => $efficiency['total_collected'],
            ]),
            ...$this->bookCards($period, $scope),
            $this->card('share_capital', $shareCapital['closing_balance'], $shareCapital['opening_balance'], $to, $previousTo, 'ready'),
            $this->card('collected_insurance', $repayments($flow)['insurance'], $repayments($previousFlow)['insurance'], $to, $previousTo, 'ready'),
            $this->card('required_allowance', $allowance['totals']['required_allowance'] ?? null, null, $to, null, $isLive ? 'ready' : 'no_snapshot'),
        ];

        $byKey = [];
        foreach ($cards as $card) {
            $card['filters_applied'] = array_values(array_intersect(
                LoanScope::fromFilters($filters)->applied(['product_id', 'officer_id', 'collector_id'])['filters_applied'],
                MetricCatalog::all()[$card['key']]['filters'],
            ));
            $byKey[$card['key']] = $card;
        }

        return array_merge(array_flip(self::CARDS), $byKey);
    }

    /**
     * Interest income and net income from the income statement, by journal
     * branch; `requires_configuration` while the books aren't set up (no chart
     * of accounts, or no interest income account).
     *
     * @param  list<int>|null  $scope
     * @return list<array<string, mixed>>
     */
    private function bookCards(DashboardPeriod $period, ?array $scope): array
    {
        $to = $period->to->toDateString();
        $previousTo = $period->previousTo->toDateString();

        if (! AccountMap::chartExists()) {
            return [
                $this->card('interest_income', null, null, $to, $previousTo, 'requires_configuration'),
                $this->card('net_income', null, null, $to, $previousTo, 'requires_configuration'),
            ];
        }

        $map = AccountMap::resolve();
        $current = $this->incomeStatement->build($period->from->toDateString(), $to, $scope);
        $previous = $this->incomeStatement->build($period->previousFrom->toDateString(), $previousTo, $scope);

        $interest = function (array $statement) use ($map): ?float {
            if (! $map->has('interest_income')) {
                return null;
            }

            $accountId = $map->accountFor('interest_income');
            $centavos = collect($statement['income'])->firstWhere('account_id', $accountId)['amount'] ?? 0;

            return $centavos / 100;
        };

        $netLabel = $this->usesCooperativeChart($map) ? 'Net Surplus' : 'Net Income';

        return [
            $this->card('interest_income', $interest($current), $interest($previous), $to, $previousTo, $map->has('interest_income') ? 'ready' : 'requires_configuration'),
            [...$this->card('net_income', $current['net_income'] / 100, $previous['net_income'] / 100, $to, $previousTo, 'ready', [
                'income' => $current['total_income'] / 100,
                'expenses' => $current['total_expenses'] / 100,
            ]), 'label' => $netLabel],
        ];
    }

    /** Whether net surplus posts to 3080 Undivided Net Surplus, the cooperative chart's account. */
    private function usesCooperativeChart(AccountMap $map): bool
    {
        if (! $map->has(YearEndCloser::NET_SURPLUS_ROLE)) {
            return false;
        }

        return AccountingAccount::query()->whereKey($map->accountFor(YearEndCloser::NET_SURPLUS_ROLE))->value('code') === '3080';
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function card(string $key, mixed $value, mixed $previousValue, string $asOf, ?string $previousAsOf, string $state, array $details = []): array
    {
        $metric = MetricCatalog::all()[$key];
        $isRatio = $metric['unit'] === 'percent';
        $ready = $state === 'ready';

        return [
            'key' => $key,
            'label' => $metric['label'],
            'unit' => $metric['unit'],
            'value' => $ready ? $value : null,
            'previous_value' => $ready || $state === 'no_snapshot' ? $previousValue : null,
            'change' => $ready ? self::change($value, $previousValue, $isRatio) : null,
            'change_unit' => $isRatio ? 'points' : 'percent',
            'as_of' => $asOf,
            'previous_as_of' => $previousValue === null ? null : $previousAsOf,
            'state' => $state,
            'filters_applied' => [],
            'details' => $ready ? $details : [],
        ];
    }

    /**
     * The change from `$previous` to `$value`: points for a ratio, else a
     * percentage of the previous value, two places; null when either is
     * missing, or the previous value is zero (undefined).
     */
    private static function change(mixed $value, mixed $previous, bool $isRatio): ?float
    {
        if ($value === null || $previous === null) {
            return null;
        }

        if ($isRatio) {
            return round((float) $value - (float) $previous, 2);
        }

        if ((float) $previous == 0.0) {
            return null;
        }

        return round(((float) $value - (float) $previous) / abs((float) $previous) * 100, 2);
    }
}
