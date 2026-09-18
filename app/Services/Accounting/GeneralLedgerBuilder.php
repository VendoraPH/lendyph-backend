<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One account's history, each movement carrying the balance after it.
 *
 * The PHP half of `runningBalances` in `@/lib/accounting/trial-balance`, with
 * the part the browser cannot do: establishing where the balance STARTED.
 *
 * ## Why the opening balance is not optional
 *
 * A ledger filtered to September that begins its running balance at zero
 * presents September's movement as the account's position. The number is on
 * screen, formatted, and wrong, and nothing about it looks partial. So the
 * opening balance is the account's signed balance as of the day BEFORE `from`,
 * and every row in range accumulates from there.
 *
 * ## Why the walk comes before the slice
 *
 * The running balance of row 101 depends on rows 1 to 100. Paginate first and
 * accumulate after, and page 2 restarts from the opening balance — so the same
 * movement shows a different balance depending on which page you happen to be
 * looking at, and the last row of the last page, which is the one a reader
 * treats as the account's current position, is out by the sum of every earlier
 * page. The movements are therefore walked in full over the range and the page
 * is cut out of the result.
 *
 * That makes a request O(rows in range) rather than O(page). It is the right
 * trade at this scale — a per-account, per-period ledger is thousands of rows,
 * not millions — and it is the reason `to`/`from` are worth passing. If a
 * single account's range ever outgrows it, the exact same figures come from two
 * aggregates instead: the opening balance below, plus a SUM over the first
 * `offset` ordered rows. What must NOT change is starting a page at zero.
 */
final class GeneralLedgerBuilder
{
    /**
     * One page of `LedgerEntry`, in a length-aware paginator.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function build(
        AccountingAccount $account,
        ?string $from,
        ?string $to,
        ?int $branchId,
        int $page,
        int $perPage,
    ): LengthAwarePaginator {
        $opening = $this->openingBalance($account, $from, $branchId);

        $movements = $this->movements($account->id, $from, $to, $branchId);

        $balances = self::runningBalances(
            (string) $account->normal_balance,
            $movements->map(static fn (object $m): array => [
                'debit' => (int) $m->debit,
                'credit' => (int) $m->credit,
            ])->all(),
            $opening,
        );

        $rows = $movements->values()->map(static fn (object $m, int $index): array => [
            'journal_id' => (int) $m->journal_id,
            'journal_no' => (string) ($m->journal_no ?? ''),
            'date' => (string) $m->date,
            'source' => (string) $m->source,
            'reference' => $m->reference,
            'description' => (string) ($m->line_description ?? $m->description),
            'branch_id' => $m->branch_id === null ? null : (int) $m->branch_id,
            'debit' => (int) $m->debit,
            'credit' => (int) $m->credit,
            'running_balance' => $balances[$index],
        ])->all();

        // The slice, LAST — after every running balance is already final.
        $total = count($rows);
        $offset = ($page - 1) * $perPage;

        return new LengthAwarePaginator(
            array_slice($rows, $offset, $perPage),
            $total,
            $perPage,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'query' => request()->query(),
            ],
        );
    }

    /**
     * The account's signed balance the moment before `$from`.
     *
     * Null `$from` means the ledger starts at the beginning of the account's
     * life, so there is nothing before it and the opening balance is zero.
     *
     * Note the boundary: everything dated STRICTLY BEFORE `$from`, i.e. up to
     * and including `from - 1 day`. An off-by-one here either double-counts the
     * first day of the range or drops it, and both look like a plausible
     * balance.
     */
    public function openingBalance(AccountingAccount $account, ?string $from, ?int $branchId): int
    {
        if ($from === null || trim($from) === '') {
            return 0;
        }

        $dayBefore = CarbonImmutable::parse($from)->subDay()->toDateString();

        $totals = DB::table('accounting_journal_lines as l')
            ->join('accounting_journals as j', 'j.id', '=', 'l.accounting_journal_id')
            ->where('l.accounting_account_id', $account->id)
            ->whereIn('j.status', AccountingJournal::HISTORICAL_STATUSES)
            ->where('j.date', '<=', $dayBefore)
            ->when($branchId !== null, fn ($q) => $q->where('j.branch_id', $branchId))
            ->selectRaw('coalesce(sum(l.debit), 0) as debit, coalesce(sum(l.credit), 0) as credit')
            ->first();

        return AccountRules::signedBalance(
            (string) $account->normal_balance,
            (int) $totals->debit,
            (int) $totals->credit,
        );
    }

    /**
     * The balance after each movement. Pure — the port of `runningBalances`.
     *
     * Accumulates in the account's NORMAL direction, so a payable's ledger
     * reads as the amount owed rather than as a debit-minus-credit that would
     * be negative for every credit-normal account on the chart.
     *
     * The movements must already be in the order they should be read; "running"
     * means nothing against an arbitrary order, which is why the caller sorts.
     *
     * @param  list<array{debit:int, credit:int}>  $movements
     * @return list<int>
     */
    public static function runningBalances(string $normalBalance, array $movements, int $openingBalance): array
    {
        $balance = $openingBalance;
        $running = [];

        foreach ($movements as $movement) {
            $balance += AccountRules::signedBalance(
                $normalBalance,
                (int) $movement['debit'],
                (int) $movement['credit'],
            );

            $running[] = $balance;
        }

        return $running;
    }

    /**
     * Every movement on the account in range, in reading order.
     *
     * Ordered by date, then by journal number, then by line — a deterministic
     * total order. Two entries on the same day have to come back in the same
     * sequence on every request or the running balances would differ between
     * page loads of the same data. `journal_no` is the register's own order and
     * is the right tiebreak; `l.id` is the final backstop for the pathological
     * case of a shared number that cannot happen (it is uniquely indexed).
     *
     * @return Collection<int, object>
     */
    private function movements(int $accountId, ?string $from, ?string $to, ?int $branchId)
    {
        return DB::table('accounting_journal_lines as l')
            ->join('accounting_journals as j', 'j.id', '=', 'l.accounting_journal_id')
            ->where('l.accounting_account_id', $accountId)
            ->whereIn('j.status', AccountingJournal::HISTORICAL_STATUSES)
            ->when($from !== null, fn ($q) => $q->where('j.date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('j.date', '<=', $to))
            ->when($branchId !== null, fn ($q) => $q->where('j.branch_id', $branchId))
            ->orderBy('j.date')
            ->orderBy('j.journal_no')
            ->orderBy('l.line_no')
            ->orderBy('l.id')
            ->select([
                'j.id as journal_id',
                'j.journal_no',
                'j.date',
                'j.source',
                'j.reference',
                'j.description',
                'j.branch_id',
                'l.description as line_description',
                'l.debit',
                'l.credit',
            ])
            ->get();
    }
}
