<?php

namespace App\Services\Accounting;

use App\Models\AccountingJournal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * How equity moved over a period, component by component.
 *
 * Needs an opening balance and the movements between it and the close, which a
 * single trial balance does not carry — which is why this comes from the server
 * rather than being regrouped client-side the way the balance sheet is.
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
 * ## What is deliberately NOT here: profit for the period
 *
 * A statement of changes in equity usually opens with net income. It is absent
 * because nothing in this ledger says it belongs to equity yet: income and
 * expense accounts carry their own balances until somebody posts a closing
 * entry, and when they do, that entry appears below as an addition to Retained
 * Earnings like any other. Synthesising a "profit for the period" row would
 * double-count for every organisation that DOES close its books, and there is
 * no way to tell from a balance which kind of organisation this is.
 *
 * So every row here is a ledger fact. The limitation is stated on the endpoint
 * rather than papered over with a figure nobody posted.
 *
 * MONEY IS IN CENTAVOS.
 */
class EquityChangesBuilder
{
    /**
     * @return array<string, mixed> `EquityChanges` in src/types/accounting.ts
     */
    public function build(string $from, string $to, ?int $branchId = null): array
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
                // `label` is the only field the table renders, and it is also
                // its React key — so the code is prefixed to keep it unique
                // even if two accounts were given the same name.
                'label' => $account['code'].' '.$account['name'],
                'account_id' => $id,
                'account_code' => $account['code'],
                'beginning' => $beginning,
                'additions' => $additions,
                'deductions' => $deductions,
                'ending' => $ending,
            ];
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
    private function contributions(?string $from, string $to, ?int $branchId): array
    {
        $rows = DB::table('accounting_journal_lines as l')
            ->join('accounting_journals as j', 'j.id', '=', 'l.accounting_journal_id')
            ->join('accounting_accounts as a', 'a.id', '=', 'l.accounting_account_id')
            ->where('a.type', 'equity')
            ->where('a.is_group', false)
            ->whereIn('j.status', AccountingJournal::HISTORICAL_STATUSES)
            ->when($from !== null, fn ($q) => $q->where('j.date', '>=', $from))
            ->where('j.date', '<=', $to)
            ->when($branchId !== null, fn ($q) => $q->where('j.branch_id', $branchId))
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
