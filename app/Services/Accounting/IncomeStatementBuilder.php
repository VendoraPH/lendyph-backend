<?php

namespace App\Services\Accounting;

use App\Models\AccountingJournal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Profit and loss for a period, in integer centavos: the server port of the
 * browser's old `subtractTrialBalances` + `buildIncomeStatement`.
 *
 * Each income and expense account's movement on posted and reversed journals
 * dated `from` to `to`, read from the journal lines in ONE grouped query
 * rather than as the difference of two trial balances. On books with no
 * closing entry the figures are the same (StatementEndpointsTest proves it),
 * and the query is the one place a filter on the journal belongs.
 *
 * ## Closing entries are left out
 *
 * A year-end close (YearEndCloser) posts `closing` journals on 31 December
 * that empty every income and expense account into net surplus, and a
 * reopened year reverses them. Neither is income or expense of the year, so
 * {@see self::withoutClosingEntries()} drops both: a closed year's statement
 * reads exactly as it did before the close, and so do the accounting
 * dashboard's period figures and trend, which come from build(). The trial
 * balance and the balance sheet still count them, which is how the result
 * reaches net surplus.
 *
 * Lines are netted in their normal direction (credit minus debit for income,
 * debit minus credit for expenses), in code order, and a line that nets to
 * zero is left out. `net_income` is negative on a loss, never clamped.
 */
final class IncomeStatementBuilder
{
    /**
     * @param  int|list<int>|null  $branchId
     * @return array<string, mixed> `IncomeStatement` in src/types/accounting.ts
     */
    public function build(string $from, string $to, int|array|null $branchId = null): array
    {
        return self::fromRows($this->movements($from, $to, $branchId), $from, $to);
    }

    /**
     * The statement from per-account rows. Pure. Rows of other types are
     * ignored, so a whole trial balance can be passed.
     *
     * @param  list<array{account_id: int, account_code: string, account_name: string, type: string, debit: int, credit: int}>  $rows
     * @return array{period: array{from: string, to: string}, income: list<array<string, mixed>>, expenses: list<array<string, mixed>>, total_income: int, total_expenses: int, net_income: int}
     */
    public static function fromRows(array $rows, string $from, string $to): array
    {
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['account_code'], (string) $b['account_code']));

        $income = self::linesOf($rows, 'income');
        $expenses = self::linesOf($rows, 'expense');
        $totalIncome = Money::sum(array_column($income, 'amount'));
        $totalExpenses = Money::sum(array_column($expenses, 'amount'));

        return [
            'period' => ['from' => $from, 'to' => $to],
            'income' => $income,
            'expenses' => $expenses,
            'total_income' => $totalIncome,
            'total_expenses' => $totalExpenses,
            'net_income' => $totalIncome - $totalExpenses,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{account_id: int, account_code: string, account_name: string, amount: int}>
     */
    private static function linesOf(array $rows, string $type): array
    {
        $lines = [];

        foreach ($rows as $row) {
            if ($row['type'] !== $type) {
                continue;
            }

            $amount = BalanceSheetBuilder::netFor($row);

            if ($amount === 0) {
                continue;
            }

            $lines[] = [
                'account_id' => (int) $row['account_id'],
                'account_code' => (string) $row['account_code'],
                'account_name' => (string) $row['account_name'],
                'amount' => $amount,
            ];
        }

        return $lines;
    }

    /**
     * Debits and credits per income and expense account over the range.
     *
     * The same status rule as the trial balance: posted AND reversed lines
     * count (a reversal and its original net out), drafts don't. Closing
     * entries and their reversals don't either (see the class docblock).
     *
     * @param  int|list<int>|null  $branchId
     * @return list<array{account_id: int, account_code: string, account_name: string, type: string, debit: int, credit: int}>
     */
    private function movements(string $from, string $to, int|array|null $branchId): array
    {
        return DB::table('accounting_journal_lines as l')
            ->join('accounting_journals as j', 'j.id', '=', 'l.accounting_journal_id')
            ->join('accounting_accounts as a', 'a.id', '=', 'l.accounting_account_id')
            ->whereIn('j.status', AccountingJournal::HISTORICAL_STATUSES)
            // Plain comparisons on a DATE column so its index stays usable.
            ->where('j.date', '>=', $from)
            ->where('j.date', '<=', $to)
            ->when($branchId !== null, fn ($q) => $q->whereIn('j.branch_id', (array) $branchId))
            ->tap(fn (Builder $q) => self::withoutClosingEntries($q, 'j'))
            ->whereIn('a.type', ['income', 'expense'])
            ->where('a.is_group', false)
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')
            ->orderBy('a.code')
            ->select([
                'a.id as account_id',
                'a.code as account_code',
                'a.name as account_name',
                'a.type',
                DB::raw('sum(l.debit) as debit'),
                DB::raw('sum(l.credit) as credit'),
            ])
            ->get()
            ->map(static fn (object $row): array => [
                'account_id' => (int) $row->account_id,
                'account_code' => (string) $row->account_code,
                'account_name' => (string) $row->account_name,
                'type' => (string) $row->type,
                // MySQL returns SUM() as a DECIMAL string.
                'debit' => (int) $row->debit,
                'credit' => (int) $row->credit,
            ])
            ->all();
    }

    /**
     * Leaves out `closing` journals and the reversals of them, on a query
     * that joins `accounting_journals` as `$journal`. Shared with the
     * year-end close, whose preview reads the year's real figures the same
     * way, so a close after a reopen sees what the first one saw.
     */
    public static function withoutClosingEntries(Builder $query, string $journal): Builder
    {
        return $query
            ->where("{$journal}.source", '!=', YearEndCloser::SOURCE)
            ->whereNotExists(fn (Builder $closing) => $closing
                ->selectRaw('1')
                ->from('accounting_journals as closing_journal')
                ->whereColumn('closing_journal.id', "{$journal}.reverses_journal_id")
                ->where('closing_journal.source', YearEndCloser::SOURCE));
    }
}
