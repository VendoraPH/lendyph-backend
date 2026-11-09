<?php

namespace App\Services\Accounting;

use App\Models\AccountingJournal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * How equity moved over a period, component by component.
 *
 * Needs an opening balance and the movements between it and the close, which a
 * single trial balance does not carry.
 *
 * ## Signs, and why `is_contra` never appears in this file
 *
 * Every figure here is expressed as a contribution TO EQUITY, which means
 * `credit - debit` for every equity account without exception. Share Capital is
 * credit-normal, so contributions come out positive. A contra-equity account —
 * Drawings, Treasury Shares — is debit-normal, so the same expression comes out
 * negative, which is exactly right: drawings REDUCE equity. Branching on
 * `is_contra` would be a second place for the sign to be wrong, and one
 * arithmetic that handles both is one place it cannot be.
 *
 * ## Earnings not yet closed
 *
 * Income and expense accounts carry their balances until a closing entry
 * moves them into equity, so the last row is derived: "Earnings not yet
 * closed", with no account of its own (`kind: earnings`). It reads income less
 * expense cumulatively: `beginning` to the day before `from`, `ending` to
 * `to`, and the period's movement in `additions` (a profit) or `deductions`
 * (a loss). That makes `total_ending` the balance sheet's total equity at
 * `to`, the one equity figure (BalanceSheetBuilder::totalEquity()).
 *
 * A closing entry never counts a peso twice. It debits income and credits
 * expense to put the result into, say, 3030 Retained Earnings inside the same
 * period: 3030 shows it as an addition, and the earnings row shows the same
 * amount leaving, as a deduction, so the total is unchanged.
 *
 * MONEY IS IN CENTAVOS.
 */
class EquityChangesBuilder
{
    /**
     * @return array<string, mixed> `EquityChanges` in src/types/accounting.ts
     */
    public function build(string $from, string $to, int|array|null $branchId = null): array
    {
        // Opening is the close of the day BEFORE. Using the first day of the
        // period would fold that day's movements into the opening balance and
        // take them off the statement.
        $dayBefore = CarbonImmutable::parse($from)->subDay()->toDateString();

        $opening = $this->contributions(null, $dayBefore, $branchId);
        $movements = $this->contributions($from, $to, $branchId);

        $accounts = $this->equityAccounts();

        $rows = [];

        foreach ($accounts as $account) {
            $id = $account['id'];

            $beginning = $opening[$id]['net'] ?? 0;
            // Additions and deductions are reported as POSITIVE magnitudes,
            // because the statement has an Additions column and a Deductions
            // column rather than one signed column. A credit to Share Capital
            // is an addition; a debit to it is a deduction.
            $additions = $movements[$id]['credits'] ?? 0;
            $deductions = $movements[$id]['debits'] ?? 0;
            $ending = $beginning + $additions - $deductions;

            // An account that neither held anything nor moved is not a
            // component of equity in this period. A row of four zeros is noise
            // on a statement, and the default chart carries several equity
            // accounts a given co-op will never use.
            if ($beginning === 0 && $additions === 0 && $deductions === 0) {
                continue;
            }

            $rows[] = [
                // The code is prefixed to keep the label unique even if two
                // accounts were given the same name.
                'label' => $account['code'].' '.$account['name'],
                'account_id' => $id,
                'account_code' => $account['code'],
                'kind' => 'account',
                'beginning' => $beginning,
                'additions' => $additions,
                'deductions' => $deductions,
                'ending' => $ending,
            ];
        }

        $earnings = $this->earningsRow($dayBefore, $to, $branchId);

        if ($earnings !== null) {
            $rows[] = $earnings;
        }

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'total_beginning' => Money::sum(array_column($rows, 'beginning')),
            'total_ending' => Money::sum(array_column($rows, 'ending')),
        ];
    }

    /**
     * The derived "Earnings not yet closed" row, or null when income and
     * expense neither held a balance nor moved.
     *
     * Income less expense is the sum of credits less debits over both types'
     * lines, so one query gives the balance on both dates.
     *
     * @param  int|list<int>|null  $branchId
     * @return array<string, mixed>|null
     */
    private function earningsRow(string $dayBefore, string $to, int|array|null $branchId): ?array
    {
        $sums = DB::table('accounting_journal_lines as l')
            ->join('accounting_journals as j', 'j.id', '=', 'l.accounting_journal_id')
            ->join('accounting_accounts as a', 'a.id', '=', 'l.accounting_account_id')
            ->whereIn('a.type', ['income', 'expense'])
            ->where('a.is_group', false)
            ->whereIn('j.status', AccountingJournal::HISTORICAL_STATUSES)
            ->where('j.date', '<=', $to)
            ->when($branchId !== null, fn ($q) => $q->whereIn('j.branch_id', (array) $branchId))
            // Summed apart and subtracted in PHP: the line columns are
            // unsigned, so `credit - debit` per line overflows on a debit.
            ->selectRaw('coalesce(sum(case when j.date <= ? then l.credit else 0 end), 0) as credits_before', [$dayBefore])
            ->selectRaw('coalesce(sum(case when j.date <= ? then l.debit else 0 end), 0) as debits_before', [$dayBefore])
            ->selectRaw('coalesce(sum(l.credit), 0) as credits')
            ->selectRaw('coalesce(sum(l.debit), 0) as debits')
            ->first();

        // MySQL returns SUM() as a DECIMAL string.
        $beginning = (int) $sums->credits_before - (int) $sums->debits_before;
        $ending = (int) $sums->credits - (int) $sums->debits;
        $movement = $ending - $beginning;

        if ($beginning === 0 && $ending === 0 && $movement === 0) {
            return null;
        }

        return [
            'label' => 'Earnings not yet closed',
            'account_id' => null,
            'account_code' => null,
            'kind' => 'earnings',
            'beginning' => $beginning,
            'additions' => max($movement, 0),
            'deductions' => max(-$movement, 0),
            'ending' => $ending,
        ];
    }

    /**
     * Every postable equity account, in code order.
     *
     * Group headings are excluded: their balance is the sum of their children,
     * so a statement carrying both would report the same capital twice and the
     * total would be exactly double.
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    private function equityAccounts(): array
    {
        return DB::table('accounting_accounts')
            ->where('type', 'equity')
            ->where('is_group', false)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
            ])
            ->all();
    }

    /**
     * Debits, credits and the net contribution to equity, per account.
     *
     * One grouped query over the range. `$from` of null means "everything ever
     * posted up to `$to`", which is how the opening balance is read.
     *
     * @return array<int, array{debits: int, credits: int, net: int}>
     */
    private function contributions(?string $from, string $to, int|array|null $branchId): array
    {
        $rows = DB::table('accounting_journal_lines as l')
            ->join('accounting_journals as j', 'j.id', '=', 'l.accounting_journal_id')
            ->join('accounting_accounts as a', 'a.id', '=', 'l.accounting_account_id')
            ->where('a.type', 'equity')
            ->where('a.is_group', false)
            ->whereIn('j.status', AccountingJournal::HISTORICAL_STATUSES)
            ->when($from !== null, fn ($q) => $q->where('j.date', '>=', $from))
            ->where('j.date', '<=', $to)
            ->when($branchId !== null, fn ($q) => $q->whereIn('j.branch_id', (array) $branchId))
            ->groupBy('a.id')
            ->select([
                'a.id as account_id',
                DB::raw('sum(l.debit) as debits'),
                DB::raw('sum(l.credit) as credits'),
            ])
            ->get();

        $contributions = [];

        foreach ($rows as $row) {
            // MySQL hands SUM() back as a DECIMAL string. Cast both, or the
            // subtraction below is string arithmetic.
            $debits = (int) $row->debits;
            $credits = (int) $row->credits;

            $contributions[(int) $row->account_id] = [
                'debits' => $debits,
                'credits' => $credits,
                // The contribution to equity. Positive for a credit-normal
                // account that was credited, and negative for a contra account
                // that was debited — see the class docblock.
                'net' => $credits - $debits,
            ];
        }

        return $contributions;
    }
}
