<?php

namespace App\Services\Accounting;

use App\Models\AccountingJournal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The BIR books of account, as `AccountingBook` in `src/types/accounting.ts`.
 *
 * Two books, one set of rows. The general journal and the general ledger are
 * not different data — they are the SAME postings in two orders, which is what
 * "book of original entry" and "book of final entry" mean:
 *
 *   - GENERAL JOURNAL: chronological. Every posting in the order it was
 *     entered, so the register reads as a diary of the period.
 *   - GENERAL LEDGER: by account. The same postings regrouped under the account
 *     they touched, so each account's activity is contiguous.
 *
 * Because they are one query with two `ORDER BY`s, the two books cannot
 * disagree about what happened — `total_debit` and `total_credit` are
 * necessarily identical between them over the same range, and
 * AccountingBooksTest asserts exactly that.
 *
 * ## Status filter
 *
 * `posted` and `reversed`, via {@see AccountingJournal::HISTORICAL_STATUSES} —
 * the same set the trial balance and the general ledger read. A reversed entry
 * is a posted historical fact whose mirror nets it to zero, and a book of
 * account that omitted it would be missing a transaction the business really
 * made. Drafts are excluded because a draft is not in the books at all; a BIR
 * book showing unposted entries would be a misrepresentation, not a preview.
 *
 * ## Why there is no row limit
 *
 * `AccountingBook` has no `truncated` flag and the books screen renders
 * `book.rows` whole, so a capped response would present a partial book as a
 * complete one — a page that says "1,000 lines" and totals only those. The
 * request is bounded by its DATE RANGE instead, which the caller chose and can
 * see. See AccountingBookController for the
 * span limit and why it is a year rather than a row count.
 */
final class BookBuilder
{
    /** The books this builder can produce. The other two BIR books are not built yet. */
    public const KINDS = ['general_journal', 'general_ledger'];

    /**
     * One book over a date range.
     *
     * @param  'general_journal'|'general_ledger'  $kind
     * @return array{kind: string, from: string, to: string, rows: list<array<string, mixed>>, total_debit: int, total_credit: int}
     */
    public function build(string $kind, string $from, string $to, ?int $branchId = null): array
    {
        $rows = $this->postings($kind, $from, $to, $branchId)
            ->map(static fn (object $row): array => [
                'date' => (string) $row->date,
                'journal_no' => (string) ($row->journal_no ?? ''),
                'reference' => $row->reference,
                // The line's own explanation when it has one, the entry's
                // otherwise. Same fallback as GeneralLedgerBuilder, so a
                // posting reads identically in the ledger and in the book.
                'particulars' => (string) ($row->line_description ?? $row->description),
                'account_code' => (string) $row->account_code,
                'account_name' => (string) $row->account_name,
                // Centavos, as integers. The column is `unsignedBigInteger`, but
                // MySQL hands aggregates and some driver configurations back as
                // strings — and `decimal:2`-style strings reaching the client is
                // the exact bug `sumCentavos` on the frontend had to be hardened
                // against after a Cash & Bank total read as ₱0.00.
                'debit' => (int) $row->debit,
                'credit' => (int) $row->credit,
            ])
            ->all();

        return [
            'kind' => $kind,
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'total_debit' => Money::sum(array_column($rows, 'debit')),
            'total_credit' => Money::sum(array_column($rows, 'credit')),
        ];
    }

    /**
     * Every posting in range, ordered for the book being built.
     *
     * A query builder rather than Eloquent: a book is a whole period's lines,
     * and hydrating a model per line to read six columns off it is the
     * difference between a report and a timeout.
     *
     * Both orderings end in `journal_no, line_no, l.id` — a deterministic total
     * order. Two entries on the same day must come back in the same sequence on
     * every request, or the book would reorder itself between page loads of
     * identical data, which for a register that BIR expects to be kept in
     * registration order is not a cosmetic problem.
     *
     * @return Collection<int, object>
     */
    private function postings(string $kind, string $from, string $to, ?int $branchId): Collection
    {
        $query = DB::table('accounting_journal_lines as l')
            ->join('accounting_journals as j', 'j.id', '=', 'l.accounting_journal_id')
            ->join('accounting_accounts as a', 'a.id', '=', 'l.accounting_account_id')
            ->whereIn('j.status', AccountingJournal::HISTORICAL_STATUSES)
            // Plain comparisons, not whereDate(): `j.date` is a DATE column, so
            // DATE() strips nothing and only stops the index on it serving as a
            // range bound. Same reasoning as TrialBalanceBuilder::movements().
            ->where('j.date', '>=', $from)
            ->where('j.date', '<=', $to)
            ->when($branchId !== null, fn ($q) => $q->where('j.branch_id', $branchId));

        // The regrouping, and the only difference between the two books.
        if ($kind === 'general_ledger') {
            // Codes are fixed-width numeric strings, so lexical order is
            // statement order: 1010 -> 1110 -> 2010 -> 4010.
            $query->orderBy('a.code')->orderBy('j.date');
        } else {
            $query->orderBy('j.date');
        }

        return $query
            ->orderBy('j.journal_no')
            ->orderBy('l.line_no')
            ->orderBy('l.id')
            ->select([
                'j.date',
                'j.journal_no',
                'j.reference',
                'j.description',
                'l.description as line_description',
                'l.debit',
                'l.credit',
                'a.code as account_code',
                'a.name as account_name',
            ])
            ->get();
    }
}
