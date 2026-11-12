<?php

namespace App\Services\Savings\Dashboard;

use App\Models\SavingsAccount;
use App\Services\Savings\SavingsMath;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The savings figures the dashboard and the summary report are built from,
 * each one query over the caller's scope, whole or grouped by branch or by
 * product. Money is integer centavos here; the callers answer two-decimal
 * strings.
 *
 * ## The scope
 *
 * `branch_ids` (null for every branch, else the caller's branches), the
 * `branch_id` filter and the `product_id` filter. An account counts in its
 * own branch and product; a time deposit in its own. A time deposit with no
 * branch is in the "no branch" group (key 0), seen only without a branch
 * list.
 *
 * ## What each flow counts
 *
 * - Deposits: `deposit` rows (member money coming in).
 * - Withdrawals: `withdrawal` and `closing` rows (money paid out), the
 *   large-transaction alert's own cash rows.
 * - Money moved between a member's savings and their time deposits (rows
 *   with a `time_deposit_id`) is neither: it never leaves the cooperative.
 * - Interest credited: `interest` rows; tax withheld: `withholding_tax` rows.
 * - Fees: `fee` rows (an excess-withdrawal fee, Dr 2050 / Cr 4080), the
 *   cooperative's income, never a withdrawal.
 * - A row that was reversed, and the reversal itself, count in none of
 *   these, as the interest postings report counts a reversed run in no
 *   total. Balances still read every row, so a period's other movements
 *   (transfers, fees, time deposit moves, reversals of earlier rows) are the
 *   rest of the change in balance.
 */
class DepositFigures
{
    public const GROUP_BRANCH = 'branch';

    public const GROUP_PRODUCT = 'product';

    public const GROUPS = [self::GROUP_BRANCH, self::GROUP_PRODUCT];

    /** The key of an ungrouped figure. */
    public const ALL = 'all';

    public const DEPOSIT_TYPES = ['deposit'];

    public const WITHDRAWAL_TYPES = ['withdrawal', 'closing'];

    /** The maturity buckets, by days from the as-of day to maturity: [key, label, from, to]. */
    public const MATURITY_BUCKETS = [
        ['0_30', '0–30 days', null, 30],
        ['31_60', '31–60 days', 31, 60],
        ['61_90', '61–90 days', 61, 90],
        ['over_90', 'Over 90 days', 91, null],
    ];

    /**
     * Each group's savings balance at the end of `$asOf`: every row's credits
     * less debits dated on or before it.
     *
     * @return array<int|string, int>
     */
    public function savingsBalances(array $scope, string $asOf, ?string $group = null): array
    {
        $query = $this->transactions($scope)->where('t.date', '<=', $asOf);

        return $this->grouped($query, $group, 'a', 'COALESCE(SUM(t.credit), 0) AS credits, COALESCE(SUM(t.debit), 0) AS debits')
            ->map(fn ($row) => SavingsMath::centavos($row->credits) - SavingsMath::centavos($row->debits))
            ->all();
    }

    /**
     * Each group's flows dated in the period (see the class notes), plus every
     * row's credits and debits. With `$byMonth`, keyed by month (YYYY-MM)
     * instead, ungrouped.
     *
     * @return array<int|string, array{deposit_count: int, deposits: int, withdrawal_count: int, withdrawals: int, interest: int, tax: int, fee_count: int, fees: int, credits: int, debits: int}>
     */
    public function flows(array $scope, string $from, string $to, ?string $group = null, bool $byMonth = false): array
    {
        $deposit = $this->counted(self::DEPOSIT_TYPES, true);
        $withdrawal = $this->counted(self::WITHDRAWAL_TYPES, true);
        $interest = $this->counted(['interest'], false);
        $tax = $this->counted(['withholding_tax'], false);
        $fee = $this->counted(['fee'], false);

        $query = $this->transactions($scope)
            ->leftJoin('savings_transactions AS r', 'r.reverses_transaction_id', '=', 't.id')
            ->whereBetween('t.date', [$from, $to]);

        $select = implode(', ', [
            "COALESCE(SUM(CASE WHEN {$deposit} THEN 1 ELSE 0 END), 0) AS deposit_count",
            "COALESCE(SUM(CASE WHEN {$deposit} THEN t.credit ELSE 0 END), 0) AS deposits",
            "COALESCE(SUM(CASE WHEN {$withdrawal} THEN 1 ELSE 0 END), 0) AS withdrawal_count",
            "COALESCE(SUM(CASE WHEN {$withdrawal} THEN t.debit ELSE 0 END), 0) AS withdrawals",
            "COALESCE(SUM(CASE WHEN {$interest} THEN t.credit ELSE 0 END), 0) AS interest",
            "COALESCE(SUM(CASE WHEN {$tax} THEN t.debit ELSE 0 END), 0) AS tax",
            "COALESCE(SUM(CASE WHEN {$fee} THEN 1 ELSE 0 END), 0) AS fee_count",
            "COALESCE(SUM(CASE WHEN {$fee} THEN t.debit ELSE 0 END), 0) AS fees",
            'COALESCE(SUM(t.credit), 0) AS credits',
            'COALESCE(SUM(t.debit), 0) AS debits',
        ]);

        $rows = $byMonth
            ? $query->groupByRaw("DATE_FORMAT(t.date, '%Y-%m')")->selectRaw("DATE_FORMAT(t.date, '%Y-%m') AS group_key, {$select}")->get()->keyBy('group_key')
            : $this->grouped($query, $group, 'a', $select);

        return $rows->map(fn ($row) => [
            'deposit_count' => (int) $row->deposit_count,
            'deposits' => SavingsMath::centavos($row->deposits),
            'withdrawal_count' => (int) $row->withdrawal_count,
            'withdrawals' => SavingsMath::centavos($row->withdrawals),
            'interest' => SavingsMath::centavos($row->interest),
            'tax' => SavingsMath::centavos($row->tax),
            'fee_count' => (int) $row->fee_count,
            'fees' => SavingsMath::centavos($row->fees),
            'credits' => SavingsMath::centavos($row->credits),
            'debits' => SavingsMath::centavos($row->debits),
        ])->all();
    }

    /**
     * Each group's accounts by today's status (statuses keep no history).
     *
     * @return array<int|string, array{active: int, dormant: int, frozen: int}>
     */
    public function accountsByStatus(array $scope, ?string $group = null): array
    {
        $query = $this->scoped(DB::table('savings_accounts AS a'), $scope, 'a');
        $select = implode(', ', array_map(
            fn (string $status) => "COALESCE(SUM(CASE WHEN a.status = '{$status}' THEN 1 ELSE 0 END), 0) AS {$status}",
            [SavingsAccount::STATUS_ACTIVE, SavingsAccount::STATUS_DORMANT, SavingsAccount::STATUS_FROZEN],
        ));

        return $this->grouped($query, $group, 'a', $select)
            ->map(fn ($row) => ['active' => (int) $row->active, 'dormant' => (int) $row->dormant, 'frozen' => (int) $row->frozen])
            ->all();
    }

    /**
     * Each group's accounts open at the end of `$asOf` (opened by then and not
     * closed by then), the balances report's rule.
     *
     * @return array<int|string, int>
     */
    public function openAccounts(array $scope, string $asOf, ?string $group = null): array
    {
        $query = $this->scoped(DB::table('savings_accounts AS a'), $scope, 'a')
            ->where('a.opened_on', '<=', $asOf)
            ->where(fn ($q) => $q->where('a.status', '!=', SavingsAccount::STATUS_CLOSED)->orWhere('a.closed_on', '>', $asOf));

        return $this->grouped($query, $group, 'a', 'COUNT(*) AS accounts')->map(fn ($row) => (int) $row->accounts)->all();
    }

    /** @return array<int|string, int> accounts opened in the period */
    public function newAccounts(array $scope, string $from, string $to, ?string $group = null): array
    {
        $query = $this->scoped(DB::table('savings_accounts AS a'), $scope, 'a')->whereBetween('a.opened_on', [$from, $to]);

        return $this->grouped($query, $group, 'a', 'COUNT(*) AS accounts')->map(fn ($row) => (int) $row->accounts)->all();
    }

    /**
     * Time deposits outstanding at the end of `$asOf`: placed by then and not
     * settled by then, the GL control's rule for 2060.
     *
     * @return array<int|string, array{count: int, principal: int, maturity_amount: int}>
     */
    public function timeDepositsOutstanding(array $scope, string $asOf, ?string $group = null): array
    {
        $query = $this->outstandingDeposits($scope, $asOf);

        return $this->grouped($query, $group, 'd', 'COUNT(*) AS deposits, COALESCE(SUM(d.principal), 0) AS principal, COALESCE(SUM(d.maturity_amount), 0) AS maturity_amount')
            ->map(fn ($row) => [
                'count' => (int) $row->deposits,
                'principal' => SavingsMath::centavos($row->principal),
                'maturity_amount' => SavingsMath::centavos($row->maturity_amount),
            ])->all();
    }

    /**
     * Time deposits placed in the period (a rollover is a new placement).
     *
     * @return array<int|string, array{count: int, principal: int}>
     */
    public function timeDepositsPlaced(array $scope, string $from, string $to, ?string $group = null): array
    {
        $query = $this->scoped(DB::table('time_deposits AS d'), $scope, 'd')->whereBetween('d.placement_date', [$from, $to]);

        return $this->grouped($query, $group, 'd', 'COUNT(*) AS deposits, COALESCE(SUM(d.principal), 0) AS principal')
            ->map(fn ($row) => ['count' => (int) $row->deposits, 'principal' => SavingsMath::centavos($row->principal)])
            ->all();
    }

    /**
     * Interest paid on time deposits settled in the period (gross and tax),
     * as settled.
     *
     * @return array<int|string, array{gross: int, tax: int}>
     */
    public function timeDepositInterest(array $scope, string $from, string $to, ?string $group = null): array
    {
        $query = $this->scoped(DB::table('time_deposits AS d'), $scope, 'd')
            ->whereNotNull('d.settled_on')
            ->whereBetween('d.settled_on', [$from, $to]);

        return $this->grouped($query, $group, 'd', 'COALESCE(SUM(d.settled_interest), 0) AS gross, COALESCE(SUM(d.settled_tax), 0) AS tax')
            ->map(fn ($row) => ['gross' => SavingsMath::centavos($row->gross), 'tax' => SavingsMath::centavos($row->tax)])
            ->all();
    }

    /**
     * Time deposits outstanding at the end of `$asOf`, by days from that day
     * to maturity. One already past maturity and not settled yet is due now,
     * in the first bucket.
     *
     * @return list<array{key: string, label: string, from_days: int|null, to_days: int|null, count: int, principal: int, maturity_amount: int}>
     */
    public function maturityBuckets(array $scope, string $asOf): array
    {
        $cases = [];
        $bindings = [];

        foreach (self::MATURITY_BUCKETS as [$key, , $from, $to]) {
            $test = $from === null ? 'DATEDIFF(d.maturity_date, ?) <= ?' : ($to === null ? 'DATEDIFF(d.maturity_date, ?) >= ?' : 'DATEDIFF(d.maturity_date, ?) BETWEEN ? AND ?');
            $values = $from === null ? [$asOf, $to] : ($to === null ? [$asOf, $from] : [$asOf, $from, $to]);

            foreach (['1' => 'count', 'd.principal' => 'principal', 'd.maturity_amount' => 'maturity_amount'] as $value => $alias) {
                $cases[] = "COALESCE(SUM(CASE WHEN {$test} THEN {$value} ELSE 0 END), 0) AS {$alias}_{$key}";
                $bindings = [...$bindings, ...$values];
            }
        }

        $row = $this->outstandingDeposits($scope, $asOf)->selectRaw(implode(', ', $cases), $bindings)->first();

        return array_map(fn (array $bucket) => [
            'key' => $bucket[0],
            'label' => $bucket[1],
            'from_days' => $bucket[2],
            'to_days' => $bucket[3],
            'count' => (int) $row->{'count_'.$bucket[0]},
            'principal' => SavingsMath::centavos($row->{'principal_'.$bucket[0]}),
            'maturity_amount' => SavingsMath::centavos($row->{'maturity_amount_'.$bucket[0]}),
        ], self::MATURITY_BUCKETS);
    }

    /** The first day any account was opened or time deposit placed in the scope, or null. */
    public function firstDay(array $scope): ?string
    {
        $days = array_filter([
            $this->scoped(DB::table('savings_accounts AS a'), $scope, 'a')->min('a.opened_on'),
            $this->scoped(DB::table('time_deposits AS d'), $scope, 'd')->min('d.placement_date'),
        ]);

        return $days === [] ? null : substr((string) min($days), 0, 10);
    }

    /** Savings rows joined to their account, in the scope. */
    private function transactions(array $scope): Builder
    {
        return $this->scoped(
            DB::table('savings_transactions AS t')->join('savings_accounts AS a', 'a.id', '=', 't.account_id'),
            $scope,
            'a',
        );
    }

    private function outstandingDeposits(array $scope, string $asOf): Builder
    {
        return $this->scoped(DB::table('time_deposits AS d'), $scope, 'd')
            ->where('d.placement_date', '<=', $asOf)
            ->where(fn ($q) => $q->whereNull('d.settled_on')->orWhere('d.settled_on', '>', $asOf));
    }

    /**
     * The SQL test for a row counted in a flow: of the types, not reversed,
     * and (for deposits and withdrawals) not a time deposit move.
     *
     * @param  list<string>  $types
     */
    private function counted(array $types, bool $memberMoney): string
    {
        $list = implode(', ', array_map(fn (string $type) => "'{$type}'", $types));

        return "t.type IN ({$list}) AND r.id IS NULL".($memberMoney ? ' AND t.time_deposit_id IS NULL' : '');
    }

    /**
     * The scope on a query over a table aliased `$alias` with `branch_id` and
     * `product_id`.
     */
    private function scoped(Builder $query, array $scope, string $alias): Builder
    {
        $ids = $scope['branch_ids'] ?? null;

        return $query
            ->when(array_key_exists('branch_ids', $scope) && $ids === [], fn ($q) => $q->whereRaw('1 = 0'))
            ->when($ids !== null && $ids !== [], fn ($q) => $q->whereIn("{$alias}.branch_id", $ids))
            ->when($scope['branch_id'] ?? null, fn ($q, $id) => $q->where("{$alias}.branch_id", $id))
            ->when($scope['product_id'] ?? null, fn ($q, $id) => $q->where("{$alias}.product_id", $id));
    }

    /**
     * Run the query whole (key `all`) or grouped by branch (0 for none) or
     * product.
     *
     * @return Collection<int|string, object>
     */
    private function grouped(Builder $query, ?string $group, string $alias, string $select): Collection
    {
        if ($group === null) {
            return collect([self::ALL => $query->selectRaw($select)->first()]);
        }

        if (! in_array($group, self::GROUPS, true)) {
            throw new InvalidArgumentException("Unknown group: {$group}.");
        }

        $column = $group === self::GROUP_BRANCH ? "COALESCE({$alias}.branch_id, 0)" : "{$alias}.product_id";

        return $query->groupByRaw($column)->selectRaw("{$column} AS group_key, {$select}")->get()
            ->keyBy(fn ($row) => (int) $row->group_key);
    }
}
