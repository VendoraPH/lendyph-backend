<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use Illuminate\Support\Collection;

/**
 * The books' loans receivable position at a date, from one trial balance.
 *
 * - `gross`: the loans-receivable accounts (the account mapped to
 *   `loans_receivable`, or every postable account under its heading).
 * - `allowance`: `allowance_credit_losses` (1200), credit normal, so a
 *   provision reads positive.
 * - `unearned_income`: `unearned_restructure_income` (1210), credit normal:
 *   interest and penalty a restructure capitalised and has not yet earned.
 * - `adjustments_clearing`: `loan_adjustments` (1180), debit normal. It should
 *   be zero; any other balance, either way, needs review.
 * - `net` = gross − allowance − unearned income (2026-11-09).
 *
 * A role that isn't mapped reads 0. Integer centavos.
 */
final class ReceivablePosition
{
    /**
     * @param  int|list<int>|null  $branchId
     * @return array{gross: int, allowance: int, unearned_income: int, adjustments_clearing: int, net: int}
     */
    public static function build(string $asOf, int|array|null $branchId): array
    {
        return self::fromTrialBalance(app(TrialBalanceBuilder::class)->build($asOf, $branchId)['rows']);
    }

    /**
     * The same position from trial-balance rows already in hand, so a caller
     * that has built the trial balance doesn't run it twice.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<int, AccountingAccount>|null  $chart  keyed by id
     * @return array{gross: int, allowance: int, unearned_income: int, adjustments_clearing: int, net: int}
     */
    public static function fromTrialBalance(array $rows, ?Collection $chart = null): array
    {
        $chart ??= AccountingAccount::query()->get()->keyBy('id');
        $mapping = AccountingAccountMapping::resolved();
        $signed = self::signedByAccount($rows, $chart);

        $gross = 0;
        foreach (self::receivableAccountIds($chart, $mapping) as $accountId) {
            $gross += $signed[$accountId] ?? 0;
        }

        $allowance = self::roleBalance('allowance_credit_losses', $mapping, $signed);
        $unearned = self::roleBalance('unearned_restructure_income', $mapping, $signed);

        return [
            'gross' => $gross,
            'allowance' => $allowance,
            'unearned_income' => $unearned,
            'adjustments_clearing' => self::roleBalance('loan_adjustments', $mapping, $signed),
            'net' => $gross - $allowance - $unearned,
        ];
    }

    /**
     * @param  array<string, int>  $mapping
     * @param  array<int, int>  $signed
     */
    private static function roleBalance(string $role, array $mapping, array $signed): int
    {
        $accountId = $mapping[$role] ?? null;

        return $accountId === null ? 0 : ($signed[$accountId] ?? 0);
    }

    /**
     * Trial-balance rows re-signed into each account's own normal direction.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<int, AccountingAccount>  $chart
     * @return array<int, int>
     */
    private static function signedByAccount(array $rows, Collection $chart): array
    {
        $signed = [];

        foreach ($rows as $row) {
            $account = $chart->get($row['account_id']);

            if ($account === null) {
                continue;
            }

            $signed[(int) $row['account_id']] = AccountRules::signedBalance(
                (string) $account->normal_balance,
                (int) $row['debit'],
                (int) $row['credit'],
            );
        }

        return $signed;
    }

    /**
     * The gross receivable is the WHOLE receivable group, not just the account
     * the `loans_receivable` role points at: moving a loan from current to past
     * due is a journal between two sibling accounts (1110 -> 1120), so counting
     * only the mapped one would shrink the portfolio every time a member fell
     * behind. A mapped account with no group heading above it counts alone.
     *
     * @param  Collection<int, AccountingAccount>  $chart
     * @param  array<string, int>  $mapping
     * @return list<int>
     */
    private static function receivableAccountIds(Collection $chart, array $mapping): array
    {
        $mappedId = $mapping['loans_receivable'] ?? null;
        $mapped = $mappedId === null ? null : $chart->get($mappedId);

        if ($mapped === null) {
            return [];
        }

        $parent = $mapped->parent_id === null ? null : $chart->get($mapped->parent_id);

        if ($parent === null || ! $parent->is_group) {
            return [(int) $mapped->id];
        }

        return $chart
            ->where('parent_id', $parent->id)
            ->where('is_group', false)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }
}
