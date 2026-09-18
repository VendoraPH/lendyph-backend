<?php

namespace App\Services\Accounting;

use App\Models\AccountingJournal;
use App\Models\AccountingReconciliation;
use App\Models\AccountingReconciliationLine;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pairing the ledger against the statement, and saying honestly what is left.
 *
 * ## What reconciling is for
 *
 * The screen says it plainly and it is worth repeating here, because it is what
 * this class is built around: a difference between the books and the statement
 * is a REAL discrepancy. Either the ledger is missing something that happened
 * or the statement is. So nothing in this file adjusts a balance, plugs a gap,
 * or rounds anything — it produces the list of lines that have no counterpart,
 * which is the only thing that lets someone find out which.
 *
 * ## The three states, and who decides them
 *
 * - `matched` — a PERSON paired these two, and the pairing is stored on the
 *   statement line. This is the only state that is written down.
 * - `possible` — the engine thinks they pair. Never persisted: a suggestion
 *   that survived a round trip would be indistinguishable from a decision, and
 *   a reconciliation whose green rows are half guesses proves nothing.
 * - `unmatched` — nothing plausible on the other side. The rows worth looking
 *   at.
 *
 * ## How a suggestion is made
 *
 * Two passes, strongest signal first, each one-to-one:
 *
 * 1. **Reference.** The statement's `external_reference` equals the journal's
 *    `reference`. A bank that echoes a deposit slip number is telling you which
 *    entry it is; nothing else the engine has comes close.
 * 2. **Amount and nearby date.** The signed amounts are EXACTLY equal and the
 *    dates are within {@see self::DATE_WINDOW_DAYS}. Exactly equal, because two
 *    amounts a centavo apart are not the same transaction — they are a
 *    transaction and a bug, and blurring that is how a reconciliation stops
 *    meaning anything.
 *
 * A ledger line already claimed by a stronger pass is never offered again, and
 * where several candidates tie, the nearest date wins and then the lowest id —
 * a total order, so the same books produce the same suggestions every time.
 * Suggestions that moved between refreshes could not be reviewed.
 *
 * ## Reading a whole page costs a fixed number of queries
 *
 * The Reconciliation screen renders every line of every reconciliation it
 * drains, so the obvious implementation — run the engine per row — is an N+1
 * that scales with the page size and only shows up once a co-op has a year of
 * history. {@see self::presentMany()} is therefore the real entry point: it
 * reads the whole page's ledger in ONE query and one balance query per distinct
 * period end, and {@see self::present()} is the single-row convenience over it.
 *
 * MONEY IS IN CENTAVOS, signed, positive for money IN — which for a
 * debit-normal cash account means `debit - credit`.
 */
class ReconciliationMatcher
{
    /**
     * How far apart a statement date and a ledger date may be and still be the
     * same event.
     *
     * Five days rather than one because value dating is real: a Friday deposit
     * posts on Monday, a cheque clears midweek, and a GCash cash-in recorded on
     * the 30th appears on the statement on the 1st. Narrower than this and the
     * engine stops suggesting the matches people actually have to make; wider
     * and a recurring weekly payment of the same amount starts matching the
     * wrong week, which is worse than no suggestion at all.
     */
    public const DATE_WINDOW_DAYS = 5;

    public function __construct(private TrialBalanceBuilder $trialBalance) {}

    /**
     * One reconciliation, shaped as `Reconciliation` in
     * `src/types/accounting.ts`.
     *
     * @return array<string, mixed>
     */
    public function present(AccountingReconciliation $reconciliation): array
    {
        return $this->presentMany(new EloquentCollection([$reconciliation]))[$reconciliation->id];
    }

    /**
     * A whole page of reconciliations, keyed by id, in a fixed number of
     * queries rather than three per row.
     *
     * @param  Collection<int, AccountingReconciliation>|EloquentCollection<int, AccountingReconciliation>  $reconciliations
     * @return array<int, array<string, mixed>>
     */
    public function presentMany(Collection $reconciliations): array
    {
        if ($reconciliations->isEmpty()) {
            return [];
        }

        // Normalised to an Eloquent collection so `loadMissing` is available
        // however the caller built it — a paginator hands over an Eloquent
        // collection, but a plain `collect([$model])` does not, and only one of
        // the two has the eager-loading methods.
        $reconciliations = EloquentCollection::make($reconciliations->all());

        $reconciliations->loadMissing(['account:id,code,name', 'lines']);

        $ledger = $this->ledgerFor($reconciliations);
        $balances = $this->balancesFor($reconciliations);

        $presented = [];

        foreach ($reconciliations as $reconciliation) {
            $accountId = $reconciliation->accounting_account_id;
            $start = $reconciliation->start_date->toDateString();
            $end = $reconciliation->end_date->toDateString();

            $window = ($ledger[$accountId] ?? new Collection)
                ->filter(static fn (array $line): bool => $line['date'] >= $start && $line['date'] <= $end)
                ->values();

            $rows = $this->pair($window, $reconciliation->lines);

            // The book balance is the account's balance AS OF the period end —
            // every posting up to that day, not only the ones inside the
            // window. A balance is cumulative; the window bounds which LINES
            // are looked at, not which history counts.
            $bookBalance = $balances[$end][$accountId] ?? 0;
            $difference = $bookBalance - $reconciliation->statement_balance;

            $presented[$reconciliation->id] = [
                'id' => $reconciliation->id,
                'account_id' => $accountId,
                'account_code' => $reconciliation->account?->code,
                'account_name' => $reconciliation->account?->name,
                'period' => $reconciliation->period,
                'start_date' => $start,
                'end_date' => $end,
                'book_balance' => $bookBalance,
                'statement_balance' => $reconciliation->statement_balance,
                // `book_balance - statement_balance`, exactly as the type says.
                'difference' => $difference,
                'is_reconciled' => $difference === 0,
                'matched_count' => $this->countOf($rows, 'matched'),
                'possible_count' => $this->countOf($rows, 'possible'),
                'unmatched_count' => $this->countOf($rows, 'unmatched'),
                'notes' => $reconciliation->notes,
                'lines' => $rows,
            ];
        }

        return $presented;
    }

    /**
     * The pairings the engine WOULD confirm — `statement_line_id =>
     * journal_line_id` for every `possible` row.
     *
     * Re-derived server-side by the match endpoint rather than taken from the
     * client, so that what gets written is what the books support now and not
     * what a stale tab remembered.
     *
     * @return array<int, int>
     */
    public function suggestions(AccountingReconciliation $reconciliation): array
    {
        $accepted = [];

        foreach ($this->present($reconciliation)['lines'] as $row) {
            if ($row['match'] === 'possible') {
                $accepted[(int) $row['statement_line_id']] = (int) $row['journal_line_id'];
            }
        }

        return $accepted;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function countOf(array $rows, string $match): int
    {
        return count(array_filter($rows, static fn (array $row): bool => $row['match'] === $match));
    }

    /**
     * Every ledger movement on every account on the page, in ONE query, grouped
     * by account.
     *
     * A query builder rather than Eloquent: this is a reporting read over
     * potentially thousands of lines, and hydrating a model per row to throw it
     * away would be the difference between a screen and a timeout.
     *
     * `HISTORICAL_STATUSES` — posted AND reversed. A reversed entry still
     * happened and its reversal is a second line; dropping either half would
     * make the ledger side of a reconciliation disagree with the balance shown
     * next to it.
     *
     * @param  Collection<int, AccountingReconciliation>  $reconciliations
     * @return array<int, Collection<int, array<string, mixed>>>
     */
    private function ledgerFor(Collection $reconciliations): array
    {
        $accountIds = $reconciliations->pluck('accounting_account_id')->unique()->values()->all();
        $from = $reconciliations->min(fn (AccountingReconciliation $r): string => $r->start_date->toDateString());
        $to = $reconciliations->max(fn (AccountingReconciliation $r): string => $r->end_date->toDateString());

        return DB::table('accounting_journal_lines as l')
            ->join('accounting_journals as j', 'j.id', '=', 'l.accounting_journal_id')
            ->whereIn('l.accounting_account_id', $accountIds)
            ->whereIn('j.status', AccountingJournal::HISTORICAL_STATUSES)
            // Plain comparisons on a DATE column, so the index on `j.date`
            // stays usable. whereDate() would wrap it in a function and force a
            // full scan of the journal table.
            ->where('j.date', '>=', $from)
            ->where('j.date', '<=', $to)
            ->orderBy('j.date')
            ->orderBy('l.id')
            ->select([
                'l.id as line_id',
                'l.accounting_account_id as account_id',
                'l.accounting_journal_id as journal_id',
                'l.debit',
                'l.credit',
                'l.description as line_description',
                'j.date',
                'j.journal_no',
                'j.reference',
                'j.description as journal_description',
            ])
            ->get()
            ->map(static fn (object $row): array => [
                'line_id' => (int) $row->line_id,
                'account_id' => (int) $row->account_id,
                'journal_id' => (int) $row->journal_id,
                // A DATE column comes back as "2026-09-18" on MySQL; normalised
                // anyway so the string comparisons above cannot be tripped by a
                // driver that appends a time.
                'date' => substr((string) $row->date, 0, 10),
                // SIGNED, positive for money in. A cash account is debit-normal,
                // so a debit is a receipt and a credit is a payment out.
                'amount' => (int) $row->debit - (int) $row->credit,
                'description' => (string) ($row->line_description ?: $row->journal_description),
                'journal_no' => $row->journal_no,
                'reference' => $row->reference,
            ])
            ->groupBy('account_id')
            ->all();
    }

    /**
     * Signed account balances as of each distinct period end on the page.
     *
     * One query per distinct `end_date` rather than one per reconciliation —
     * a page of twelve monthly reconciliations across five accounts is twelve
     * queries, not sixty.
     *
     * Through TrialBalanceBuilder rather than a sum of its own. The Cash & Bank
     * screen, the dashboard and the balance sheet all read that builder, and
     * two aggregates over the same rows would eventually disagree over a
     * reversal — at which point both figures would be plausible and neither
     * checkable.
     *
     * @param  Collection<int, AccountingReconciliation>  $reconciliations
     * @return array<string, array<int, int>> end date => (account id => centavos)
     */
    private function balancesFor(Collection $reconciliations): array
    {
        $balances = [];

        foreach ($reconciliations->groupBy(fn (AccountingReconciliation $r): string => $r->end_date->toDateString()) as $end => $group) {
            $balances[$end] = $this->trialBalance->signedBalances(
                (string) $end,
                null,
                $group->pluck('accounting_account_id')->unique()->values()->all(),
            );
        }

        return $balances;
    }

    /**
     * The worksheet: confirmed pairs, then suggestions, then the leftovers.
     *
     * A matched or suggested pair is emitted as ONE row, not two. Two rows for
     * one event is how a worksheet doubles in length and stops being readable —
     * and the count of unmatched items, which is the number anyone acts on,
     * would come out wrong.
     *
     * @param  Collection<int, array<string, mixed>>  $ledger
     * @param  Collection<int, AccountingReconciliationLine>  $statement
     * @return list<array<string, mixed>>
     */
    private function pair(Collection $ledger, Collection $statement): array
    {
        /** @var array<int, array<string, mixed>> $ledgerById */
        $ledgerById = $ledger->keyBy('line_id')->all();

        $statement = $statement->sortBy([['date', 'asc'], ['id', 'asc']])->values();

        $rows = [];
        $claimed = [];
        $pending = [];

        // ── Pass 0: the pairings a person already confirmed ──
        foreach ($statement as $line) {
            $matchedId = $line->matched_journal_line_id;

            if ($matchedId !== null && isset($ledgerById[$matchedId])) {
                $claimed[$matchedId] = true;
                $rows[] = $this->pairedRow($line, $ledgerById[$matchedId], 'matched');

                continue;
            }

            // A confirmed pairing whose ledger line has fallen outside the
            // window — someone moved the dates, or the journal was re-dated —
            // is treated as UNCONFIRMED rather than shown as matched anyway. A
            // green row whose counterpart is not in the period proves nothing.
            $pending[] = $line;
        }

        /*
         * Both passes are driven by an INDEX rather than by scanning the ledger
         * per statement line, and on a busy account that is the difference
         * between a page and a timeout.
         *
         * The obvious shape — for each statement line, test every ledger line —
         * is O(statement x ledger) and the numbers are not small: a thousand
         * statement lines (the cap on the create endpoint) against a month of
         * collections on one bank account is millions of comparisons in PHP,
         * per reconciliation, and the list screen DRAINS every reconciliation
         * it has. Grouping the ledger by the thing being matched on turns each
         * pass into a hash lookup against a handful of candidates.
         */
        $byReference = [];
        $byAmount = [];

        foreach ($ledgerById as $lineId => $candidate) {
            if ($candidate['reference'] !== null && $candidate['reference'] !== '') {
                // Lower-cased so the lookup is the case-insensitive comparison
                // this used to do with strcasecmp().
                $byReference[mb_strtolower((string) $candidate['reference'])][] = $lineId;
            }

            $byAmount[$candidate['amount']][] = $lineId;
        }

        // ── Pass 1: the reference the bank echoed back ──
        $pending = $this->suggest(
            $pending,
            $ledgerById,
            $claimed,
            $rows,
            static function (AccountingReconciliationLine $line) use ($byReference): array {
                $reference = $line->external_reference;

                if ($reference === null || $reference === '') {
                    return [];
                }

                return $byReference[mb_strtolower($reference)] ?? [];
            },
            // A matching reference is the bank naming the entry. No date test:
            // that is the point of trusting it over pass 2.
            static fn (): bool => true,
        );

        // ── Pass 2: the same amount, a few days either side ──
        $pending = $this->suggest(
            $pending,
            $ledgerById,
            $claimed,
            $rows,
            // EXACT amount equality, so this is a lookup and not a range scan —
            // and exact is what the rule already required. Two amounts a
            // centavo apart are a transaction and a bug, not one transaction.
            static fn (AccountingReconciliationLine $line): array => $byAmount[$line->amount] ?? [],
            fn (AccountingReconciliationLine $line, array $candidate): bool => $this->daysApart(
                $line->date->toDateString(),
                $candidate['date'],
            ) <= self::DATE_WINDOW_DAYS,
        );

        // ── What is left, on both sides ──
        foreach ($pending as $line) {
            $rows[] = [
                'journal_id' => null,
                'date' => $line->date->toDateString(),
                'description' => $line->description,
                'amount' => $line->amount,
                'match' => 'unmatched',
                'external_reference' => $line->external_reference,
                'journal_no' => null,
                'source' => 'statement',
                'statement_line_id' => $line->id,
                'journal_line_id' => null,
            ];
        }

        foreach ($ledgerById as $lineId => $candidate) {
            if (isset($claimed[$lineId])) {
                continue;
            }

            $rows[] = [
                'journal_id' => $candidate['journal_id'],
                'date' => $candidate['date'],
                'description' => $candidate['description'],
                'amount' => $candidate['amount'],
                'match' => 'unmatched',
                // A ledger row carries the journal's own reference here, so the
                // Reference column is populated for both sides of the worksheet
                // rather than only for imported lines.
                'external_reference' => $candidate['reference'],
                'journal_no' => $candidate['journal_no'],
                'source' => 'ledger',
                'statement_line_id' => null,
                'journal_line_id' => $lineId,
            ];
        }

        // Date order across both sides, then a total tiebreak so the same books
        // always render in the same order.
        usort($rows, static fn (array $a, array $b): int => [
            $a['date'], $a['statement_line_id'] ?? 0, $a['journal_line_id'] ?? 0,
        ] <=> [
            $b['date'], $b['statement_line_id'] ?? 0, $b['journal_line_id'] ?? 0,
        ]);

        return $rows;
    }

    /**
     * One suggestion pass. Returns the statement lines still unpaired.
     *
     * `$lookup` returns the ledger line ids worth considering for a given
     * statement line — a hash lookup into an index built by the caller, never a
     * scan. `$fits` is the rest of the test, applied only to that handful.
     *
     * @param  list<AccountingReconciliationLine>  $pending
     * @param  array<int, array<string, mixed>>  $ledgerById
     * @param  array<int, true>  $claimed
     * @param  list<array<string, mixed>>  $rows
     * @param  callable(AccountingReconciliationLine): list<int>  $lookup
     * @param  callable(AccountingReconciliationLine, array<string, mixed>): bool  $fits
     * @return list<AccountingReconciliationLine>
     */
    private function suggest(
        array $pending,
        array $ledgerById,
        array &$claimed,
        array &$rows,
        callable $lookup,
        callable $fits,
    ): array {
        $stillPending = [];

        foreach ($pending as $line) {
            $candidates = [];

            foreach ($lookup($line) as $lineId) {
                $candidate = $ledgerById[$lineId];

                if (isset($claimed[$lineId]) || ! $fits($line, $candidate)) {
                    continue;
                }

                $candidates[] = [
                    $this->daysApart($line->date->toDateString(), $candidate['date']),
                    $lineId,
                    $candidate,
                ];
            }

            if ($candidates === []) {
                $stillPending[] = $line;

                continue;
            }

            // Nearest date wins, then the lowest id. A total order, so the same
            // books produce the same suggestions on every refresh.
            usort($candidates, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

            [, $lineId, $candidate] = $candidates[0];

            $claimed[$lineId] = true;
            $rows[] = $this->pairedRow($line, $candidate, 'possible');
        }

        return $stillPending;
    }

    /**
     * One row standing for both halves of a pair.
     *
     * The STATEMENT's description and reference are shown, because that is what
     * the person reconciling is reading off the bank's page; the LEDGER's
     * `journal_id` is carried so the row links back to the entry. The amount is
     * the LEDGER's — this account's balance is built from the books, and
     * showing the bank's figure beside our `journal_id` would attribute one to
     * the other.
     *
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function pairedRow(AccountingReconciliationLine $line, array $candidate, string $match): array
    {
        return [
            'journal_id' => $candidate['journal_id'],
            'date' => $candidate['date'],
            'description' => $line->description,
            'amount' => $candidate['amount'],
            'match' => $match,
            'external_reference' => $line->external_reference,
            'journal_no' => $candidate['journal_no'],
            'source' => 'both',
            'statement_line_id' => $line->id,
            'journal_line_id' => $candidate['line_id'],
        ];
    }

    /** Whole days between two calendar dates, either direction. */
    private function daysApart(string $a, string $b): int
    {
        return (int) abs(
            CarbonImmutable::parse($a)->startOfDay()->diffInDays(CarbonImmutable::parse($b)->startOfDay())
        );
    }
}
