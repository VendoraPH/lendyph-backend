<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use App\Models\AccountingJournal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The cash receipts and cash disbursements books in columns, one row per
 * journal, as `ColumnBook` in the frontend's `src/types/accounting.ts`.
 *
 * The journals are exactly the line books' ({@see BookBuilder::movedMoney()}),
 * posted and reversed, in date then journal-number order. Each journal's lines
 * are laid out in three places:
 *
 *   - MONEY: every money account (`cash_kind` set, not a heading), netted per
 *     journal. Receipts read debit − credit, disbursements credit − debit, so
 *     a GCash Cash In reads +amount + charge on Cash and −amount on GCash.
 *   - COLUMNS: one per mapped role in the book's list, on the book's own side
 *     (credits for receipts, debits for disbursements), keyed by the role and
 *     labelled with the mapped account's name. An unmapped role has no column.
 *   - SUNDRY: every other line, with its own side. That includes a column's
 *     account on the other side, such as a restructure's Dr Loans Receivable.
 *
 * So every row balances: for receipts, money = columns + sundry credit −
 * sundry debit, and disbursements mirror it. The totals are added up here, in
 * integer centavos; the browser only lays them out.
 */
final class ColumnBookBuilder
{
    /** The two column books, named as BookBuilder names the line books. */
    public const KINDS = ['cash_receipts', 'cash_disbursements'];

    /** Credit columns of the cash receipts book, by mapping role, in order. */
    public const RECEIPT_COLUMNS = [
        'loans_receivable',
        'interest_income',
        'penalty_income',
        'insurance_premium_payable',
        'share_capital',
        'borrower_advances',
        'gcash_service_income',
    ];

    /** Debit columns of the cash disbursements book, by mapping role, in order. */
    public const DISBURSEMENT_COLUMNS = [
        'loans_receivable',
        'accounts_payable',
        'share_capital',
        'borrower_advances',
    ];

    public function __construct(private BookBuilder $books) {}

    /**
     * One column book over a date range.
     *
     * @param  'cash_receipts'|'cash_disbursements'  $kind
     * @return array<string, mixed>
     */
    public function build(string $kind, string $from, string $to, int|array|null $branchId = null): array
    {
        $receipts = $kind === 'cash_receipts';
        $columns = $this->columns($receipts ? self::RECEIPT_COLUMNS : self::DISBURSEMENT_COLUMNS);
        $columnByAccount = array_column($columns, 'key', 'account_id');

        $rows = [];
        $moneyAccounts = [];
        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($this->lines($receipts, $from, $to, $branchId)->groupBy('journal_id') as $lines) {
            $first = $lines->first();
            $money = [];
            $columnAmounts = [];
            $sundry = [];

            foreach ($lines as $line) {
                $debit = (int) $line->debit;
                $credit = (int) $line->credit;
                $accountId = (int) $line->account_id;
                $totalDebit += $debit;
                $totalCredit += $credit;

                if ($line->cash_kind !== null && ! $line->is_group) {
                    $money[$accountId] = ($money[$accountId] ?? 0) + ($receipts ? $debit - $credit : $credit - $debit);
                    $moneyAccounts[$accountId] = [
                        'account_id' => $accountId,
                        'account_code' => (string) $line->account_code,
                        'account_name' => (string) $line->account_name,
                    ];

                    continue;
                }

                $ownSide = $receipts ? $credit : $debit;

                if (isset($columnByAccount[$accountId]) && $ownSide > 0) {
                    $key = $columnByAccount[$accountId];
                    $columnAmounts[$key] = ($columnAmounts[$key] ?? 0) + $ownSide;

                    continue;
                }

                $sundry[] = [
                    'account_code' => (string) $line->account_code,
                    'account_name' => (string) $line->account_name,
                    'debit' => $debit,
                    'credit' => $credit,
                ];
            }

            $rows[] = [
                'journal_id' => (int) $first->journal_id,
                'journal_no' => (string) ($first->journal_no ?? ''),
                'date' => (string) $first->date,
                'reference' => $first->reference,
                'particulars' => (string) $first->description,
                'money' => array_filter($money, static fn (int $amount): bool => $amount !== 0),
                'columns' => $this->inOrder(array_column($columns, 'key'), $columnAmounts),
                'sundry' => $sundry,
            ];
        }

        $moneyColumns = $this->moneyColumns($moneyAccounts, $rows);
        $moneyOrder = array_column($moneyColumns, 'account_id');

        $moneyTotals = array_fill_keys($moneyOrder, 0);
        $columnTotals = array_fill_keys(array_column($columns, 'key'), 0);
        $sundryDebit = 0;
        $sundryCredit = 0;

        foreach ($rows as $index => $row) {
            foreach ($row['money'] as $accountId => $amount) {
                $moneyTotals[$accountId] += $amount;
            }

            foreach ($row['columns'] as $key => $amount) {
                $columnTotals[$key] += $amount;
            }

            $sundryDebit += Money::sum(array_column($row['sundry'], 'debit'));
            $sundryCredit += Money::sum(array_column($row['sundry'], 'credit'));

            $rows[$index]['money'] = (object) $this->inOrder($moneyOrder, $row['money']);
            $rows[$index]['columns'] = (object) $row['columns'];
        }

        $sundryNet = $receipts ? $sundryCredit - $sundryDebit : $sundryDebit - $sundryCredit;

        return [
            'kind' => $kind,
            'from' => $from,
            'to' => $to,
            'money_columns' => $moneyColumns,
            'columns' => array_map(
                static fn (array $column): array => ['key' => $column['key'], 'label' => $column['label']],
                $columns,
            ),
            'rows' => $rows,
            'totals' => [
                'money' => (object) $moneyTotals,
                'columns' => (object) $columnTotals,
                'sundry_debit' => $sundryDebit,
                'sundry_credit' => $sundryCredit,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
            ],
            'is_balanced' => Money::isBalanced(Money::sum($moneyTotals), Money::sum($columnTotals) + $sundryNet)
                && Money::isBalanced($totalDebit, $totalCredit),
        ];
    }

    /**
     * The book's columns, in the list's order, for roles mapped to an account.
     *
     * A role is left out when it's unmapped, when its account no longer
     * exists, when it points at a money account (whose lines are already in
     * the money columns), or when an earlier role in the list has the same
     * account: one account fills one column, so no column is always empty.
     *
     * @param  list<string>  $roles
     * @return list<array{key: string, label: string, account_id: int}>
     */
    private function columns(array $roles): array
    {
        $mapping = AccountingAccountMapping::resolved();
        $accounts = AccountingAccount::query()
            ->whereIn('id', array_values(array_intersect_key($mapping, array_flip($roles))))
            ->get(['id', 'name', 'cash_kind', 'is_group'])
            ->keyBy('id');

        $columns = [];
        $taken = [];

        foreach ($roles as $role) {
            $account = isset($mapping[$role]) ? $accounts->get($mapping[$role]) : null;

            if ($account === null || ($account->cash_kind !== null && ! $account->is_group) || isset($taken[$account->id])) {
                continue;
            }

            $taken[$account->id] = true;
            $columns[] = ['key' => $role, 'label' => (string) $account->name, 'account_id' => (int) $account->id];
        }

        return $columns;
    }

    /**
     * Every line of the book's journals, grouped by journal in book order.
     *
     * The same journals, statuses, dates and branch scope as
     * BookBuilder::build() reads for the line book.
     *
     * @return Collection<int, object>
     */
    private function lines(bool $receipts, string $from, string $to, int|array|null $branchId): Collection
    {
        return DB::table('accounting_journal_lines as l')
            ->join('accounting_journals as j', 'j.id', '=', 'l.accounting_journal_id')
            ->join('accounting_accounts as a', 'a.id', '=', 'l.accounting_account_id')
            ->whereIn('j.status', AccountingJournal::HISTORICAL_STATUSES)
            ->where('j.date', '>=', $from)
            ->where('j.date', '<=', $to)
            ->when($branchId !== null, fn ($q) => $q->whereIn('j.branch_id', (array) $branchId))
            ->whereIn('j.id', $this->books->movedMoney($receipts ? 'in' : 'out', $from, $to))
            ->orderBy('j.date')
            ->orderBy('j.journal_no')
            ->orderBy('j.id')
            ->orderBy('l.line_no')
            ->orderBy('l.id')
            ->select([
                'j.id as journal_id',
                'j.journal_no',
                'j.date',
                'j.reference',
                'j.description',
                'l.debit',
                'l.credit',
                'a.id as account_id',
                'a.code as account_code',
                'a.name as account_name',
                'a.cash_kind',
                'a.is_group',
            ])
            ->get();
    }

    /**
     * The money accounts that moved in the book, in code order.
     *
     * @param  array<int, array{account_id: int, account_code: string, account_name: string}>  $accounts
     * @param  list<array{money: array<int, int>}>  $rows
     * @return list<array{account_id: int, account_code: string, account_name: string}>
     */
    private function moneyColumns(array $accounts, array $rows): array
    {
        $moved = [];

        foreach ($rows as $row) {
            $moved += $row['money'];
        }

        $columns = array_values(array_intersect_key($accounts, $moved));

        usort($columns, static fn (array $a, array $b): int => [$a['account_code'], $a['account_id']] <=> [$b['account_code'], $b['account_id']]);

        return $columns;
    }

    /**
     * A map's entries in the given key order, leaving out keys it doesn't hold.
     *
     * @param  list<int|string>  $order
     * @param  array<int|string, int>  $map
     * @return array<int|string, int>
     */
    private function inOrder(array $order, array $map): array
    {
        $ordered = [];

        foreach ($order as $key) {
            if (isset($map[$key])) {
                $ordered[$key] = $map[$key];
            }
        }

        return $ordered;
    }
}
