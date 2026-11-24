<?php

namespace App\Services\Dashboard;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use App\Models\AccountingJournal;
use App\Models\AccountingOpeningBalance;
use App\Services\Accounting\BooksState;
use App\Services\Accounting\Money;
use App\Services\Accounting\OpeningBalancePoster;
use App\Services\BranchAttribution;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The share capital control (Phase 6 EB-D2): the members' share capital
 * subledger set against the account mapped to the `share_capital` role
 * (3060 Share Capital), by branch, as of a date.
 *
 * - `subledger`: the share capital ledger's balance (credits − debits) dated
 *   on or before `as_of`, each row in the branch it posted in
 *   (BranchAttribution: its own branch, else its member's), the Share Capital
 *   report's closing balance for that branch.
 * - `books`: the account's balance in its normal (credit) direction on posted
 *   and reversed journals dated on or before `as_of`, by journal branch, the
 *   trial balance's rule and figure.
 * - `difference` = books − subledger; a row is reconciled at ₱0.00.
 *
 * Until the books are ready (BooksState: the opening balances finalised)
 * and while no account is set for the role, `books`, `difference` and
 * `is_reconciled` read null: a missing opening balance is not a difference.
 *
 * Every branch in scope has a row (₱0.00 when idle). With every branch in
 * scope (`branches:view_all`), a last "No branch" row holds journals with no
 * branch (opening balances) and subledger rows whose member has no branch.
 *
 * A subledger row the share capital import brought over (`is_imported`) and
 * dated on or before the finalised opening balances' date counts under "No
 * branch" too: its book side is in those opening balances, which carry no
 * branch (finish run round 2, decision 13; lead's standard pick). One dated
 * after them, or while none are finalised, stays in its branch: it genuinely
 * has no book side.
 *
 * Integer centavos.
 * Reads only.
 */
final class ShareCapitalControl
{
    public const NO_BRANCH = 'No branch';

    /**
     * @param  list<int>|null  $branchIds  null for every branch
     * @return array{as_of: string, books_state: array{state: string, label: string, reason: string|null}, account: array{id: int, code: string, name: string}|null, rows: list<array{branch_id: int|null, branch_name: string, subledger: int, books: int|null, difference: int|null, is_reconciled: bool|null}>, totals: array{subledger: int, books: int|null, difference: int|null, is_reconciled: bool|null}, is_reconciled: bool|null}
     */
    public function build(string $asOf, ?array $branchIds): array
    {
        $booksState = BooksState::current();
        $account = $this->account();
        $subledger = $this->subledger($asOf, $branchIds);
        $books = $account === null || $booksState['state'] !== BooksState::READY
            ? null
            : $this->books($asOf, $branchIds, (int) $account->id);

        $rows = [];
        foreach ($this->branches($branchIds, [...array_keys($subledger), ...array_keys($books ?? [])]) as $branch) {
            $rows[] = $this->row($branch->id, $branch->name, $subledger[$branch->id] ?? 0, $books === null ? null : ($books[$branch->id] ?? 0));
        }

        if ($branchIds === null) {
            $rows[] = $this->row(null, self::NO_BRANCH, $subledger[''] ?? 0, $books === null ? null : ($books[''] ?? 0));
        }

        $totals = $this->row(
            null,
            '',
            Money::sum(array_column($rows, 'subledger')),
            $books === null ? null : Money::sum(array_column($rows, 'books')),
        );
        unset($totals['branch_id'], $totals['branch_name']);

        return [
            'as_of' => $asOf,
            'books_state' => $booksState,
            'account' => $account === null ? null : [
                'id' => (int) $account->id,
                'code' => (string) $account->code,
                'name' => (string) $account->name,
            ],
            'rows' => $rows,
            'totals' => $totals,
            'is_reconciled' => $totals['is_reconciled'],
        ];
    }

    /**
     * @return array{branch_id: int|null, branch_name: string, subledger: int, books: int|null, difference: int|null, is_reconciled: bool|null}
     */
    private function row(?int $branchId, string $name, int $subledger, ?int $books): array
    {
        $difference = $books === null ? null : $books - $subledger;

        return [
            'branch_id' => $branchId,
            'branch_name' => $name,
            'subledger' => $subledger,
            'books' => $books,
            'difference' => $difference,
            'is_reconciled' => $difference === null ? null : $difference === 0,
        ];
    }

    private function account(): ?AccountingAccount
    {
        $accountId = AccountingAccountMapping::resolved()['share_capital'] ?? null;

        return $accountId === null ? null : AccountingAccount::query()->find($accountId);
    }

    /**
     * The subledger's balance by the branch each row counts in ('' for none).
     *
     * @param  list<int>|null  $branchIds
     * @return array<int|string, int>
     */
    private function subledger(string $asOf, ?array $branchIds): array
    {
        $branch = BranchAttribution::shareCapitalBranchSql('share_capital_ledger', 'borrowers');
        $opening = $this->openingBalancesDate();
        $bindings = [];

        if ($opening !== null) {
            $branch = "CASE WHEN share_capital_ledger.is_imported = 1 AND share_capital_ledger.date <= ? THEN NULL ELSE {$branch} END";
            $bindings = [$opening];
        }

        // Grouped by its alias: MySQL takes a bound date in the select list and
        // the same one in GROUP BY for two different expressions.
        return DB::table('share_capital_ledger')
            ->leftJoin('borrowers', 'borrowers.id', '=', 'share_capital_ledger.borrower_id')
            ->tap(BranchAttribution::shareCapitalInBranches($branchIds))
            ->where('share_capital_ledger.date', '<=', $asOf)
            ->selectRaw("{$branch} as counted_branch_id", $bindings)
            ->selectRaw('COALESCE(SUM(share_capital_ledger.credit), 0) as credit, COALESCE(SUM(share_capital_ledger.debit), 0) as debit')
            ->groupBy('counted_branch_id')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                ($row->counted_branch_id === null ? '' : (int) $row->counted_branch_id) => self::centavos($row->credit) - self::centavos($row->debit),
            ])
            ->all();
    }

    /**
     * The date the finalised opening balances are as of: the finalised entry's,
     * else its posted journal's (BooksState reads the books ready on either).
     * Null while none are finalised.
     */
    private function openingBalancesDate(): ?string
    {
        $date = DB::table('accounting_opening_balances')->where('status', AccountingOpeningBalance::STATUS_FINALISED)->orderBy('id')->value('as_of')
            ?? DB::table('accounting_journals')->where('source', OpeningBalancePoster::SOURCE)->where('status', 'posted')->orderBy('id')->value('date');

        return $date === null ? null : substr((string) $date, 0, 10);
    }

    /**
     * The account's credit balance by journal branch ('' for none), on the
     * trial balance's lines: posted and reversed journals dated on or before
     * `as_of`.
     *
     * @param  list<int>|null  $branchIds
     * @return array<int|string, int>
     */
    private function books(string $asOf, ?array $branchIds, int $accountId): array
    {
        return DB::table('accounting_journal_lines as l')
            ->join('accounting_journals as j', 'j.id', '=', 'l.accounting_journal_id')
            ->whereIn('j.status', AccountingJournal::HISTORICAL_STATUSES)
            ->where('l.accounting_account_id', $accountId)
            ->where('j.date', '<=', $asOf)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('j.branch_id', $branchIds))
            ->groupBy('j.branch_id')
            ->select('j.branch_id')
            ->selectRaw('COALESCE(SUM(l.credit), 0) as credit, COALESCE(SUM(l.debit), 0) as debit')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                ($row->branch_id === null ? '' : (int) $row->branch_id) => (int) $row->credit - (int) $row->debit,
            ])
            ->all();
    }

    /**
     * The branches with a row: the caller's, or every active branch plus any
     * other with figures, in id order.
     *
     * @param  list<int>|null  $branchIds
     * @param  list<int|string>  $withFigures
     * @return Collection<int, object{id: int, name: string}>
     */
    private function branches(?array $branchIds, array $withFigures): Collection
    {
        $withFigures = array_values(array_filter($withFigures, 'is_int'));

        return DB::table('branches')
            ->when(
                $branchIds !== null,
                fn ($q) => $q->whereIn('id', $branchIds),
                fn ($q) => $q->where(fn ($w) => $w->where('is_active', true)->orWhereIn('id', $withFigures)),
            )
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (object $branch): object => (object) ['id' => (int) $branch->id, 'name' => (string) $branch->name]);
    }

    private static function centavos(mixed $pesos): int
    {
        return Money::toCentavos((string) $pesos)
            ?? throw new RuntimeException("Share capital control: '{$pesos}' is not a convertible peso amount.");
    }
}
