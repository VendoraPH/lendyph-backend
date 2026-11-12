<?php

namespace App\Services\Savings\Dashboard;

use App\Services\Savings\Reports\GlControl;
use App\Services\Savings\SavingsMath;
use Carbon\CarbonImmutable;

/**
 * The Savings dashboard (the Savings task's TASK 35 and TASK 62): every figure
 * from the server, for the caller's branches and the period, branch and
 * product filters.
 *
 * - `kpis`: balances and time deposits at the end of the period (`date_to`),
 *   the period's flows ({@see DepositFigures}), accounts by today's status
 *   (statuses keep no history), time deposits maturing within 30 days of
 *   `date_to`, and the GL control as of `date_to`.
 * - `series`: up to 12 months ending with `date_to`'s month, from the first
 *   month the scope holds a record: each month's closing balances (at
 *   `date_to` for the last) and flows.
 * - `by_product` and `by_branch`: the deposit summary's rows and totals.
 * - `maturity_buckets`: time deposits outstanding at `date_to` by days to
 *   maturity.
 *
 * The GL control has no product: with a product filter it still reads the
 * whole of each branch, and says so (`gl_control.product_filter_ignored`).
 */
class SavingsDashboard
{
    public const SERIES_MONTHS = 12;

    public const GL_WARNING = 'Deposit Subsidiary Ledger does not reconcile with General Ledger.';

    public function __construct(
        private readonly DepositFigures $figures,
        private readonly DepositSummary $summary,
        private readonly GlControl $control,
    ) {}

    /** @return array<string, mixed> */
    public function build(array $scope, string $from, string $to): array
    {
        $flows = $this->figures->flows($scope, $from, $to)[DepositFigures::ALL];
        $savings = $this->figures->savingsBalances($scope, $to)[DepositFigures::ALL];
        $deposits = $this->figures->timeDepositsOutstanding($scope, $to)[DepositFigures::ALL];
        $statuses = $this->figures->accountsByStatus($scope)[DepositFigures::ALL];
        $tdInterest = $this->figures->timeDepositInterest($scope, $from, $to)[DepositFigures::ALL];
        $buckets = $this->figures->maturityBuckets($scope, $to);

        return [
            'date_from' => $from,
            'date_to' => $to,
            'kpis' => [
                'total_deposits' => SavingsMath::pesos($savings + $deposits['principal']),
                'savings_balance' => SavingsMath::pesos($savings),
                'time_deposits' => ['count' => $deposits['count'], 'amount' => SavingsMath::pesos($deposits['principal'])],
                'accounts' => [...$statuses, 'as_of' => now()->toDateString()],
                'new_accounts' => $this->figures->newAccounts($scope, $from, $to)[DepositFigures::ALL],
                'deposits' => ['count' => $flows['deposit_count'], 'amount' => SavingsMath::pesos($flows['deposits'])],
                'withdrawals' => ['count' => $flows['withdrawal_count'], 'amount' => SavingsMath::pesos($flows['withdrawals'])],
                'net_deposit_movement' => SavingsMath::pesos($flows['deposits'] - $flows['withdrawals']),
                'interest_credited' => [
                    'gross' => SavingsMath::pesos($flows['interest']),
                    'tax' => SavingsMath::pesos($flows['tax']),
                    'net' => SavingsMath::pesos($flows['interest'] - $flows['tax']),
                ],
                'time_deposit_interest_paid' => ['gross' => SavingsMath::pesos($tdInterest['gross']), 'tax' => SavingsMath::pesos($tdInterest['tax'])],
                'maturing_30_days' => $this->money($buckets[0], ['count', 'principal', 'maturity_amount']),
                'gl_control' => $this->glControl($scope, $to),
            ],
            'series' => $this->series($scope, $to),
            'by_product' => $this->breakdown($scope, $from, $to, DepositFigures::GROUP_PRODUCT),
            'by_branch' => $this->breakdown($scope, $from, $to, DepositFigures::GROUP_BRANCH),
            'maturity_buckets' => array_map(fn (array $bucket) => $this->money($bucket, ['key', 'label', 'from_days', 'to_days', 'count', 'principal', 'maturity_amount']), $buckets),
        ];
    }

    /** @return array<string, mixed> */
    private function glControl(array $scope, string $asOf): array
    {
        $control = $this->control->build($asOf, $scope['branch_ids'] ?? null, isset($scope['branch_id']) ? (int) $scope['branch_id'] : null);

        return [
            'as_of' => $control['as_of'],
            'is_balanced' => $control['is_balanced'],
            'differences' => $control['differences'],
            'subledger_balance' => $control['totals']['subledger_balance'],
            'gl_balance' => $control['totals']['gl_balance'],
            'difference' => $control['totals']['difference'],
            'warning' => $control['is_balanced'] ? null : self::GL_WARNING,
            'product_filter_ignored' => ($scope['product_id'] ?? null) !== null,
        ];
    }

    /** @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>} */
    private function breakdown(array $scope, string $from, string $to, string $group): array
    {
        $summary = $this->summary->build($scope, $from, $to, $group);

        return ['rows' => $summary['rows'], 'totals' => $summary['totals']];
    }

    /**
     * Month by month, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    private function series(array $scope, string $to): array
    {
        $first = $this->figures->firstDay($scope);
        $end = CarbonImmutable::parse($to);

        if ($first === null || $first > $to) {
            return [];
        }

        $start = $end->startOfMonth()->subMonths(self::SERIES_MONTHS - 1)->max(CarbonImmutable::parse($first)->startOfMonth());
        $flows = $this->figures->flows($scope, $start->toDateString(), $to, null, true);
        $previous = $this->totalOn($scope, $start->subDay()->toDateString());
        $series = [];

        for ($month = $start; $month->lessThanOrEqualTo($end); $month = $month->addMonth()) {
            $monthEnd = $month->endOfMonth()->min($end)->toDateString();
            $savings = $this->figures->savingsBalances($scope, $monthEnd)[DepositFigures::ALL];
            $deposits = $this->figures->timeDepositsOutstanding($scope, $monthEnd)[DepositFigures::ALL]['principal'];
            $flow = $flows[$month->format('Y-m')] ?? ['deposits' => 0, 'withdrawals' => 0, 'interest' => 0, 'deposit_count' => 0, 'withdrawal_count' => 0];
            $total = $savings + $deposits;

            $series[] = [
                'month' => $month->format('Y-m'),
                'label' => $month->format('M Y'),
                'as_of' => $monthEnd,
                'savings_balance' => SavingsMath::pesos($savings),
                'time_deposits' => SavingsMath::pesos($deposits),
                'total_deposits' => SavingsMath::pesos($total),
                'deposit_count' => $flow['deposit_count'],
                'deposits' => SavingsMath::pesos($flow['deposits']),
                'withdrawal_count' => $flow['withdrawal_count'],
                'withdrawals' => SavingsMath::pesos($flow['withdrawals']),
                'interest_credited' => SavingsMath::pesos($flow['interest']),
                'net_movement' => SavingsMath::pesos($total - $previous),
            ];
            $previous = $total;
        }

        return $series;
    }

    private function totalOn(array $scope, string $asOf): int
    {
        return $this->figures->savingsBalances($scope, $asOf)[DepositFigures::ALL]
            + $this->figures->timeDepositsOutstanding($scope, $asOf)[DepositFigures::ALL]['principal'];
    }

    /**
     * @param  array<string, mixed>  $figures
     * @param  list<string>  $keep
     * @return array<string, mixed>
     */
    private function money(array $figures, array $keep): array
    {
        $out = $figures;

        foreach (['principal', 'maturity_amount'] as $field) {
            if (array_key_exists($field, $out)) {
                $out[$field] = SavingsMath::pesos($out[$field]);
            }
        }

        return array_intersect_key($out, array_flip($keep));
    }
}
