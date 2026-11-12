<?php

namespace App\Services\Savings\Dashboard;

use App\Models\Branch;
use App\Models\SavingsProduct;
use App\Services\Savings\SavingsMath;
use Carbon\CarbonImmutable;

/**
 * The deposit summary for a period, by branch or by product (the Savings
 * task's TASK 32 and TASK 36: Deposit Summary, Product Performance, Branch
 * Deposit Report), and the dashboard's breakdowns.
 *
 * Each row and the totals carry the same figures:
 * - the savings balance at the start and end of the period, the deposits,
 *   withdrawals, interest credited and tax withheld in it
 *   ({@see DepositFigures} says what each counts), the other movements (the
 *   rest of the change in balance) and the net movement (closing less
 *   opening);
 * - accounts opened in the period and open at its end;
 * - time deposits outstanding at its end, placed in it, and the interest
 *   paid on those settled in it; interest expense is savings interest plus
 *   time deposit interest;
 * - total deposits: the savings balance plus time deposits outstanding.
 *
 * Every active branch the caller can see is listed (₱0.00 where nothing
 * moved), and every active product; an inactive one is listed when it has a
 * figure. Time deposits with no branch get their own row, for callers who see
 * every branch. The totals are worked out over the whole scope, never by
 * adding the rows, so a test can prove the rows add up to them.
 */
class DepositSummary
{
    public function __construct(private readonly DepositFigures $figures) {}

    /**
     * @return array{group_by: string, date_from: string, date_to: string, rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function build(array $scope, string $from, string $to, string $group): array
    {
        $grouped = $this->collect($scope, $from, $to, $group);
        $whole = $this->collect($scope, $from, $to, null);

        $rows = [];

        foreach ($this->groups($scope, $group, $grouped) as $key => $name) {
            $rows[] = [
                'group_id' => $key === 0 ? null : $key,
                'group_name' => $name,
                ...$this->line($grouped, $key),
            ];
        }

        return [
            'group_by' => $group,
            'date_from' => $from,
            'date_to' => $to,
            'rows' => $rows,
            'totals' => $this->line($whole, DepositFigures::ALL),
        ];
    }

    /**
     * Every figure, keyed by group.
     *
     * @return array<string, array<int|string, mixed>>
     */
    private function collect(array $scope, string $from, string $to, ?string $group): array
    {
        $dayBefore = CarbonImmutable::parse($from)->subDay()->toDateString();

        return [
            'opening' => $this->figures->savingsBalances($scope, $dayBefore, $group),
            'closing' => $this->figures->savingsBalances($scope, $to, $group),
            'flows' => $this->figures->flows($scope, $from, $to, $group),
            'new' => $this->figures->newAccounts($scope, $from, $to, $group),
            'open' => $this->figures->openAccounts($scope, $to, $group),
            'outstanding' => $this->figures->timeDepositsOutstanding($scope, $to, $group),
            'placed' => $this->figures->timeDepositsPlaced($scope, $from, $to, $group),
            'td_interest' => $this->figures->timeDepositInterest($scope, $from, $to, $group),
        ];
    }

    /**
     * One row's figures, as two-decimal strings.
     *
     * @param  array<string, array<int|string, mixed>>  $figures
     * @return array<string, int|string>
     */
    private function line(array $figures, int|string $key): array
    {
        $flows = $figures['flows'][$key] ?? ['deposit_count' => 0, 'deposits' => 0, 'withdrawal_count' => 0, 'withdrawals' => 0, 'interest' => 0, 'tax' => 0, 'credits' => 0, 'debits' => 0];
        $opening = $figures['opening'][$key] ?? 0;
        $closing = $figures['closing'][$key] ?? 0;
        $net = $closing - $opening;
        $counted = $flows['deposits'] - $flows['withdrawals'] + $flows['interest'] - $flows['tax'];
        $outstanding = $figures['outstanding'][$key] ?? ['count' => 0, 'principal' => 0, 'maturity_amount' => 0];
        $placed = $figures['placed'][$key] ?? ['count' => 0, 'principal' => 0];
        $tdInterest = $figures['td_interest'][$key] ?? ['gross' => 0, 'tax' => 0];

        return [
            'opening_balance' => SavingsMath::pesos($opening),
            'deposit_count' => $flows['deposit_count'],
            'deposits' => SavingsMath::pesos($flows['deposits']),
            'withdrawal_count' => $flows['withdrawal_count'],
            'withdrawals' => SavingsMath::pesos($flows['withdrawals']),
            'interest_credited' => SavingsMath::pesos($flows['interest']),
            'tax_withheld' => SavingsMath::pesos($flows['tax']),
            'other_movements' => SavingsMath::pesos($net - $counted),
            'net_movement' => SavingsMath::pesos($net),
            'closing_balance' => SavingsMath::pesos($closing),
            'new_accounts' => $figures['new'][$key] ?? 0,
            'open_accounts' => $figures['open'][$key] ?? 0,
            'time_deposit_count' => $outstanding['count'],
            'time_deposits' => SavingsMath::pesos($outstanding['principal']),
            'time_deposits_placed_count' => $placed['count'],
            'time_deposits_placed' => SavingsMath::pesos($placed['principal']),
            'time_deposit_interest' => SavingsMath::pesos($tdInterest['gross']),
            'interest_expense' => SavingsMath::pesos($flows['interest'] + $tdInterest['gross']),
            'total_deposits' => SavingsMath::pesos($closing + $outstanding['principal']),
        ];
    }

    /**
     * The groups to list, id => name, by name: active ones in the scope plus
     * any with a figure (0 is "No branch").
     *
     * @param  array<string, array<int|string, mixed>>  $figures
     * @return array<int, string>
     */
    private function groups(array $scope, string $group, array $figures): array
    {
        $moved = [];

        foreach ($figures as $byKey) {
            foreach ($byKey as $key => $value) {
                if ($this->isNonZero($value)) {
                    $moved[] = (int) $key;
                }
            }
        }

        $moved = array_values(array_unique($moved));
        $model = $group === DepositFigures::GROUP_BRANCH ? Branch::query() : SavingsProduct::query();
        $limit = $group === DepositFigures::GROUP_BRANCH
            ? ($scope['branch_id'] ?? null ? [(int) $scope['branch_id']] : ($scope['branch_ids'] ?? null))
            : ($scope['product_id'] ?? null ? [(int) $scope['product_id']] : null);

        $listed = $model
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', array_filter($moved)))
            ->when($limit !== null, fn ($q) => $q->whereIn('id', $limit))
            ->orderBy('name')
            ->orderBy('id')
            ->pluck('name', 'id')
            ->all();

        if ($group === DepositFigures::GROUP_BRANCH && in_array(0, $moved, true)) {
            $listed[0] = 'No branch';
        }

        return $listed;
    }

    private function isNonZero(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $part) {
                if ($part !== 0) {
                    return true;
                }
            }

            return false;
        }

        return $value !== 0;
    }
}
