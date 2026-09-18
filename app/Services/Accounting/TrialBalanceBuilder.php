<?php

namespace App\Services\Accounting;

use App\Models\AccountingJournal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The trial balance: every account's net balance, in the column it actually
 * landed on, with the two columns adding to the same figure.
 *
 * The PHP half of `@/lib/accounting/trial-balance`. The two must agree exactly,
 * because the balance sheet and the income statement are built CLIENT-SIDE by
 * regrouping these rows — so this is not one report among several, it is the
 * single source every balance-sheet figure descends from. A difference here
 * means something wrote to the ledger without going through the double-entry
 * gate.
 *
 * ## The status filter, which is the easiest thing in this module to get wrong
 *
 * Posted lines count. So do REVERSED ones. A reversed entry is not a mistake
 * that was rubbed out — it is a posted historical fact whose mirror entry nets
 * it to zero, and both halves are on the books. Count only `posted` and every
 * reversal is included while the entry it reverses is not: each reversed
 * account then shows the exact negative of a transaction it no longer has, and
 * the trial balance reports an imbalance that does not exist. Drafts are
 * excluded because a draft is not in the books at all.
 *
 * {@see AccountingJournal::HISTORICAL_STATUSES}.
 */
final class TrialBalanceBuilder
{
    /**
     * The trial balance as of a date, as `TrialBalance` in
     * `src/types/accounting.ts`.
     *
     * @return array{as_of: string, rows: list<array<string, mixed>>, total_debit: int, total_credit: int, difference: int, is_balanced: bool}
     */
    public function build(string $asOf, ?int $branchId = null): array
    {
        return self::fromBalances($this->movements($asOf, $branchId)->all(), $asOf);
    }

    /**
     * Every account's balance in ITS OWN normal direction, keyed by account id.
     *
     * The same aggregate `build()` runs, deliberately — this is what
     * `Account.balance` and the Cash & Bank screen read, and computing it from
     * a second query would let the Cash & Bank total drift from the trial
     * balance with nothing to say which of the two was right.
     *
     * Positive means the account sits where it should. Negative means it has
     * swung the other way — an overdrawn cash account — which is a real
     * condition and is reported rather than clamped.
     *
     * Accounts with no movement are absent rather than zero, so a caller can
     * tell "never posted to" from "posted to and netted out". Callers that want
     * a figure for every account default the missing ones to 0 themselves.
     *
     * `$accountIds` narrows the aggregate to the accounts a caller actually
     * needs — a chart page, or one account — without becoming a second query
     * with its own idea of which statuses count. Same rows, same arithmetic,
     * fewer of them.
     *
     * @param  list<int>|null  $accountIds
     * @return array<int, int> account id => signed centavos
     */
    public function signedBalances(?string $asOf = null, ?int $branchId = null, ?array $accountIds = null): array
    {
        $balances = [];

        if ($accountIds !== null && $accountIds === []) {
            return $balances;
        }

        foreach ($this->movements($asOf, $branchId, $accountIds) as $row) {
            $balances[(int) $row['account_id']] = AccountRules::signedBalance(
                (string) $row['normal_balance'],
                (int) $row['debit'],
                (int) $row['credit'],
            );
        }

        return $balances;
    }

    /**
     * Assembles the report from pre-totalled accounts. Pure — no database.
     *
     * The direct port of `buildTrialBalance`, and the reason it is a separate
     * function is that the placement rules below are the part worth testing in
     * isolation:
     *
     * 1. NETTED. An account debited ₱500 and credited ₱200 is one ₱300 debit,
     *    not both figures side by side, or every account that moved in both
     *    directions is double-counted in the totals.
     * 2. PLACED by where it actually landed, not by where it should be. An
     *    overdrawn cash account has a credit balance and is shown in the credit
     *    column; recording it as a negative debit would make the columns
     *    balance on paper while hiding a condition someone needs to see.
     * 3. DROPPED if it nets to zero, so the report shows the accounts that
     *    moved rather than the whole chart.
     *
     * Group headings are excluded outright: their balance is the sum of their
     * children, so including both counts the same money twice. The query below
     * already leaves them out; this is the guard for every other caller.
     *
     * @param  list<array{account_id:int, account_code:string, account_name:string, type:string, normal_balance:string, is_group?:bool, debit:int, credit:int}>  $balances
     * @return array{as_of: string, rows: list<array<string, mixed>>, total_debit: int, total_credit: int, difference: int, is_balanced: bool}
     */
    public static function fromBalances(array $balances, string $asOf): array
    {
        $rows = [];

        foreach ($balances as $balance) {
            if ($balance['is_group'] ?? false) {
                continue;
            }

            $net = AccountRules::signedBalance(
                (string) $balance['normal_balance'],
                (int) $balance['debit'],
                (int) $balance['credit'],
            );

            if ($net === 0) {
                continue;
            }

            // `net` is positive when the account sits on its normal side. A
            // negative net means it swung the other way, so the amount belongs
            // in the opposite column — as a POSITIVE number, because a trial
            // balance has no negatives.
            $onNormalSide = $net > 0;
            $amount = abs($net);
            $isDebitColumn = $balance['normal_balance'] === 'debit' ? $onNormalSide : ! $onNormalSide;

            $rows[] = [
                'account_id' => (int) $balance['account_id'],
                'account_code' => (string) $balance['account_code'],
                'account_name' => (string) $balance['account_name'],
                'type' => (string) $balance['type'],
                'debit' => $isDebitColumn ? $amount : 0,
                'credit' => $isDebitColumn ? 0 : $amount,
            ];
        }

        // Codes are fixed-width numeric strings, so lexical order is statement
        // order: 1010 -> 1110 -> 2010 -> 4010.
        usort($rows, static fn (array $a, array $b): int => strcmp($a['account_code'], $b['account_code']));

        $totalDebit = Money::sum(array_column($rows, 'debit'));
        $totalCredit = Money::sum(array_column($rows, 'credit'));
        $difference = $totalDebit - $totalCredit;

        return [
            'as_of' => $asOf,
            'rows' => $rows,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'difference' => $difference,
            'is_balanced' => $difference === 0,
        ];
    }

    /**
     * One grouped query over the lines that count, joined to their accounts.
     *
     * A query builder rather than Eloquent: this reads a whole ledger's worth of
     * rows down to one row per account, and hydrating a model per line to throw
     * it away would be the difference between a report and a timeout.
     *
     * `$asOf` of null means "everything ever posted" — which is what the Cash &
     * Bank screen wants, since a money account's balance is its balance, not its
     * balance as of some cut-off.
     *
     * @param  list<int>|null  $accountIds
     * @return Collection<int, array<string, mixed>>
     */
    private function movements(?string $asOf, ?int $branchId, ?array $accountIds = null): Collection
    {
        return DB::table('accounting_journal_lines as l')
            ->join('accounting_journals as j', 'j.id', '=', 'l.accounting_journal_id')
            ->join('accounting_accounts as a', 'a.id', '=', 'l.accounting_account_id')
            ->whereIn('j.status', AccountingJournal::HISTORICAL_STATUSES)
            // A plain comparison, not whereDate(): `j.date` is a DATE column, so
            // wrapping it in a function would make the index on it unusable.
            ->when($asOf !== null, fn ($q) => $q->where('j.date', '<=', $asOf))
            ->when($branchId !== null, fn ($q) => $q->where('j.branch_id', $branchId))
            ->when($accountIds !== null, fn ($q) => $q->whereIn('l.accounting_account_id', $accountIds))
            ->where('a.is_group', false)
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type', 'a.normal_balance')
            ->orderBy('a.code')
            ->select([
                'a.id as account_id',
                'a.code as account_code',
                'a.name as account_name',
                'a.type',
                'a.normal_balance',
                DB::raw('sum(l.debit) as debit'),
                DB::raw('sum(l.credit) as credit'),
            ])
            ->get()
            ->map(static fn (object $row): array => [
                'account_id' => (int) $row->account_id,
                'account_code' => (string) $row->account_code,
                'account_name' => (string) $row->account_name,
                'type' => (string) $row->type,
                'normal_balance' => (string) $row->normal_balance,
                'is_group' => false,
                // MySQL hands SUM() back as a DECIMAL string. Cast, do not let
                // it travel as a string: every consumer of this treats it as an
                // integer number of centavos.
                'debit' => (int) $row->debit,
                'credit' => (int) $row->credit,
            ]);
    }
}
