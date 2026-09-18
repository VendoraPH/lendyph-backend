<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\AccountingJournalLine;
use App\Services\SequenceCode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The ONLY writer of journals and journal lines.
 *
 * Everything that moves money in this application comes through here: the
 * manual entry screen, the automatic postings raised by a loan release or a
 * collection, an opening balance, a reversal. One writer is not tidiness — it
 * is the reason the rules below can be stated as guarantees rather than as
 * things most callers remember to do.
 *
 * ## The rules, and why each one is at THIS layer
 *
 * - **Totals are recomputed from the persisted lines, never accepted.** The
 *   frontend sends `total_debit`/`total_credit` alongside the lines and they
 *   are read here for exactly nothing. A client that computed them from a
 *   different set of rows than it sent — an unconverted amount, a dropped
 *   blank line, an off-by-one in a loop — would otherwise turn a client bug
 *   into a ledger bug, and the header would agree with itself while disagreeing
 *   with its own lines. No report joins the two, so nothing would ever catch it.
 * - **Accounts are re-checked at POST time, not at draft time.** An account can
 *   be deactivated, or turned into a group heading, between the afternoon
 *   someone drafts an entry and the morning it is approved.
 * - **The number is allocated under a row lock.** Two concurrent posts would
 *   otherwise read the same highest journal number and both claim the next one.
 * - **A posted entry is never edited.** Correcting it means {@see self::reverse()},
 *   which writes a mirror and leaves both halves on the record.
 *
 * Money is centavos throughout. {@see Money} does the arithmetic; nothing here
 * reimplements it.
 */
final class JournalPoster
{
    /** Keeps MAX_LINES * Money::maxCentavos() inside PHP_INT_MAX, so the sum is exact. */
    public const MAX_LINES = 500;

    /**
     * The closed-period lock lives HERE, and that placement is the whole reason
     * it works.
     *
     * A closed period that still accepts postings is not closed, and there are
     * a dozen ways into the books: the manual entry screen, an expense, a fund
     * transfer, a reversal, and every automatic entry a loan release or a
     * collection raises. Checking the period in each of those is a list that
     * would be incomplete the first time somebody added a thirteenth. This
     * class is already the ONE writer of journals, so a check here is a check
     * on all of them, including the ones not written yet.
     *
     * @see PeriodGuard for why an absent period does NOT lock, and why the read
     *      takes a shared lock.
     */
    public function __construct(private PeriodGuard $periods) {}

    /**
     * Creates a draft.
     *
     * A draft affects nothing: it has no number, it is excluded from every
     * report, and it may be edited or thrown away. It is a piece of paper on a
     * desk, not an entry in the books.
     *
     * Deliberately does NOT require two lines or a balance — that is what
     * {@see self::post()} is the gate for, and a half-typed entry has to be
     * savable or the manual entry screen cannot exist. The per-line shape IS
     * checked, because a line that is both sides at once or neither is not an
     * unfinished thought, it is a malformed row, and the database would refuse
     * it with a 500 rather than something a screen can render.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array{account_id:int,description?:string|null,debit?:int,credit?:int}>  $lines
     */
    public function draft(array $attributes, array $lines, ?int $userId = null): AccountingJournal
    {
        return DB::transaction(function () use ($attributes, $lines, $userId): AccountingJournal {
            // Checked on the DRAFT as well as on the post, so that someone
            // typing an entry into a month that was closed last week is told
            // now rather than after they have finished filling it in. The post
            // is still checked independently — a draft created while the period
            // was open and submitted after it closed has to be refused, and
            // only the check in commit() sees that.
            if (isset($attributes['date'])) {
                $this->periods->assertOpen((string) $attributes['date']);
            }

            $journal = AccountingJournal::create(array_merge(
                ['source' => 'manual'],
                $attributes,
                ['created_by' => $userId],
            ));

            $this->writeLines($journal, $lines);

            return $journal->refresh();
        });
    }

    /**
     * Replaces a draft's contents.
     *
     * Lines are rewritten wholesale rather than diffed: a journal's lines are
     * meaningful only as a set (they have to balance together), so matching
     * them up one by one would buy nothing and would let a partial update leave
     * an entry that balances against lines the caller never sent. Passing
     * `null` for `$lines` leaves them alone, which is how a caller edits only
     * the date or the description.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array{account_id:int,description?:string|null,debit?:int,credit?:int}>|null  $lines
     */
    public function updateDraft(AccountingJournal $journal, array $attributes, ?array $lines = null): AccountingJournal
    {
        return DB::transaction(function () use ($journal, $attributes, $lines): AccountingJournal {
            $fresh = $this->lock($journal->id);

            // Refused HERE, as a 422, rather than left to the model's guard —
            // which throws a RuntimeException and would surface as a 500 on
            // what is an ordinary "someone opened a stale tab" mistake. The
            // model guard stays as the backstop for callers that skip this.
            if ($fresh->status !== 'draft') {
                throw ValidationException::withMessages([
                    'journal' => [$this->immutableMessage($fresh)],
                ]);
            }

            // Re-dating a draft INTO a closed period is the same act as
            // creating one there, and is refused for the same reason. Only the
            // incoming date is checked: a draft that already sits in a closed
            // month may still be edited out of it, which is how someone fixes
            // exactly this mistake.
            if (isset($attributes['date'])) {
                $this->periods->assertOpen((string) $attributes['date']);
            }

            $fresh->update($attributes);

            if ($lines !== null) {
                // Query-builder delete, not the relation's: the rows are going
                // away as a set and firing a model event per line would only
                // re-check the draft status this method has already taken a
                // lock to establish.
                AccountingJournalLine::query()
                    ->where('accounting_journal_id', $fresh->id)
                    ->delete();

                $this->writeLines($fresh, $lines);
            }

            return $fresh->refresh();
        });
    }

    /**
     * Puts a draft into the books. The point of no return.
     *
     * Every check runs inside one transaction with the lines locked, so a
     * concurrent edit cannot change what is being posted between the check and
     * the write.
     */
    public function post(AccountingJournal $journal, ?int $userId = null): AccountingJournal
    {
        return $this->commit($journal, $userId, allowInactiveAccounts: false);
    }

    /**
     * The posting transaction itself.
     *
     * `$allowInactiveAccounts` is only ever true on the reversal path — see
     * {@see self::reverse()} for why — and is private so that no caller outside
     * this class can reach for it.
     */
    private function commit(
        AccountingJournal $journal,
        ?int $userId,
        bool $allowInactiveAccounts,
    ): AccountingJournal {
        return DB::transaction(function () use ($journal, $userId, $allowInactiveAccounts): AccountingJournal {
            $fresh = $this->lock($journal->id);

            if ($fresh->status !== 'draft') {
                throw ValidationException::withMessages([
                    'journal' => [$this->immutableMessage($fresh)],
                ]);
            }

            /*
             * THE CLOSED-PERIOD LOCK.
             *
             * Read from the STORED date rather than from anything a caller
             * passed, and read here rather than at draft time, because the two
             * moments are days apart in normal use: a bookkeeper drafts an
             * entry on the 30th and it is approved on the 3rd, by which time
             * the month may have been signed off. Only this check sees that.
             *
             * The read takes a shared lock that is held for the rest of this
             * transaction, and PeriodCalendar::close() takes an exclusive one,
             * so a post and a close cannot overtake each other — see
             * PeriodGuard.
             */
            $this->periods->assertOpen(
                $fresh->date instanceof \DateTimeInterface
                    ? $fresh->date->format('Y-m-d')
                    : substr((string) $fresh->date, 0, 10),
                $fresh->journal_no ?: 'This entry',
            );

            // Re-read under a lock. The in-memory copy the caller holds may
            // have been loaded before someone else edited the draft, and the
            // totals below have to be computed from what is ACTUALLY stored.
            $lines = AccountingJournalLine::query()
                ->where('accounting_journal_id', $fresh->id)
                ->orderBy('line_no')
                ->lockForUpdate()
                ->get();

            if ($lines->count() < 2) {
                throw ValidationException::withMessages([
                    'lines' => ['A journal entry needs at least two lines — one debit and one credit.'],
                ]);
            }

            $this->assertEveryAccountIsPostable($lines, $allowInactiveAccounts);
            $this->assertEveryAmountIsPlausible($lines);
            $this->assertTheLineCountCannotOverflowTheSum($lines);

            // THE TOTALS. Summed over the rows just read under lock, never over
            // anything the client sent. Integer centavos, so the sum is exact.
            $totalDebit = Money::sum($lines->pluck('debit')->all());
            $totalCredit = Money::sum($lines->pluck('credit')->all());

            if (! Money::isBalanced($totalDebit, $totalCredit)) {
                $difference = Money::format(abs($totalDebit - $totalCredit));

                throw ValidationException::withMessages([
                    'balance' => [
                        "Debits and credits differ by {$difference}. An entry must balance before it can be posted.",
                    ],
                ]);
            }

            // Balanced at zero is still balanced, and still records nothing.
            // Posting it would spend a journal number on an entry that moves no
            // money and appears on no statement.
            if ($totalDebit <= 0) {
                throw ValidationException::withMessages([
                    'balance' => ['This entry records nothing — enter the amounts before posting.'],
                ]);
            }

            // The other half of the bound: many individually legal lines whose
            // SUM runs past it. The per-line check above cannot see this one,
            // and this one cannot see that one — see
            // self::assertEveryAmountIsPlausible() for why the order matters.
            if ($totalDebit > Money::maxCentavos()) {
                throw ValidationException::withMessages([
                    'balance' => [
                        'This entry totals '.Money::format($totalDebit).', which is beyond any amount this '
                        .'system records. Check the figures — an amount that size is a typo or a unit error, '
                        .'not a balance.',
                    ],
                ]);
            }

            $fresh->journal_no = $this->allocateJournalNo();
            $fresh->status = 'posted';
            $fresh->total_debit = $totalDebit;
            $fresh->total_credit = $totalCredit;
            $fresh->posted_by = $userId;
            $fresh->posted_at = now();
            $fresh->save();

            return $fresh->refresh();
        });
    }

    /**
     * Draft and post in one step, for the automatic engine.
     *
     * Loan release, collection, fee and penalty postings are raised in the SAME
     * transaction as the lending event that causes them — do it in a second
     * request and a crash between the two leaves the books disagreeing with the
     * portfolio, with nothing to point at the difference.
     *
     * ## Idempotent on (postable, source)
     *
     * Automatic postings get retried: a queue redelivery, a timeout the caller
     * resolved as a failure, an operator who clicked twice. A retry that wrote a
     * SECOND balanced journal would be invisible — the trial balance would still
     * balance and every statement would still be internally consistent, with the
     * portfolio silently double-counted. So a caller that supplies a `postable`
     * gets the entry that already exists rather than a new one.
     *
     * Checked twice on purpose. The SELECT answers the ordinary retry cheaply;
     * the unique index answers the case the SELECT cannot — two retries racing,
     * where both read "no journal yet" and both then insert. The inner
     * transaction is a savepoint when a caller has already opened one, so the
     * failed attempt rolls back to it and the surrounding lending transaction
     * survives to return the existing entry.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array{account_id:int,description?:string|null,debit?:int,credit?:int}>  $lines
     */
    public function postImmediately(array $attributes, array $lines, ?int $userId = null): AccountingJournal
    {
        $existing = $this->existingPostingFor($attributes);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(
                fn (): AccountingJournal => $this->post($this->draft($attributes, $lines, $userId), $userId)
            );
        } catch (UniqueConstraintViolationException $e) {
            $existing = $this->existingPostingFor($attributes);

            if ($existing === null) {
                // Not the idempotency index — a duplicate journal_no, say.
                // Re-throw rather than swallow: a number collision means the
                // sequence is broken and must not be hidden behind a retry.
                throw $e;
            }

            return $existing;
        }
    }

    /**
     * Writes the mirror image of a posted entry and marks the original reversed.
     *
     * Built from the STORED lines, never from client input. A caller that could
     * supply the lines of a reversal could "reverse" an entry into a different
     * shape than the one it undoes, and the pair would not net to zero — the
     * books would balance and both entries would look correct in isolation.
     *
     * The original is not edited beyond the two link columns: its own lines,
     * totals, number and date stay exactly as posted, so what was believed and
     * when is still on the record. That is what makes the trail auditable
     * rather than merely current.
     */
    public function reverse(
        AccountingJournal $journal,
        string $date,
        ?string $reason = null,
        ?int $userId = null,
    ): AccountingJournal {
        return DB::transaction(function () use ($journal, $date, $reason, $userId): AccountingJournal {
            $original = $this->lock($journal->id);

            if ($original->status === 'reversed' || $original->reversed_by_journal_id !== null) {
                throw ValidationException::withMessages([
                    'journal' => ["Journal {$original->journal_no} has already been reversed."],
                ]);
            }

            if ($original->status !== 'posted') {
                throw ValidationException::withMessages([
                    'journal' => [
                        'Only a posted journal can be reversed; '
                        .($original->journal_no ?: 'this entry').' is a draft.',
                    ],
                ]);
            }

            $mirror = $original->lines()->get()->map(fn (AccountingJournalLine $line): array => [
                'account_id' => $line->accounting_account_id,
                'description' => $line->description,
                // The swap, and the whole of it.
                'debit' => $line->credit,
                'credit' => $line->debit,
            ])->all();

            $reversal = $this->draft([
                'date' => $date,
                'source' => 'reversal',
                // The original's reference, so both halves are findable by the
                // document they came from.
                'reference' => $original->reference,
                'description' => $this->reversalDescription($original, $reason),
                'branch_id' => $original->branch_id,
                'reverses_journal_id' => $original->id,
                // Deliberately NO postable. The reversal's link to the world is
                // `reverses_journal_id`; copying the document reference would
                // consume the idempotency slot for (document, reversal) and
                // make a later, legitimately different reversal impossible.
            ], $mirror, $userId);

            /*
             * Posted with the INACTIVE check relaxed, and only that one.
             *
             * A reversal's lines are copied from an entry that was already
             * validated when it posted: nothing new enters the books, an
             * existing fact is undone. Deactivating an account means "take no
             * NEW history", and refusing on that basis would strand the
             * operator completely — a posted entry cannot be edited, cannot be
             * deleted, and would then not be reversible either, which is the
             * moment a reversal is most needed. The only escape would be to
             * reactivate the account, reverse, and deactivate again, producing
             * the identical ledger rows by a route nobody documented.
             *
             * Group headings stay refused. An account CAN be turned into one
             * after being posted to, and a heading's balance is the sum of its
             * subtree, so a line against it is double-counted either way.
             */
            $reversal = $this->commit($reversal, $userId, allowInactiveAccounts: true);

            // The narrow write the model's immutability guard allows, and the
            // only change a posted entry ever takes.
            $original->status = 'reversed';
            $original->reversed_by_journal_id = $reversal->id;
            $original->save();

            return $reversal->refresh();
        });
    }

    /**
     * The next `JE-000000`, under a row lock.
     *
     * Ordered by `journal_no` rather than by `id`, for the reason
     * LoanService::release() orders by `loan_account_number`: drafts are posted
     * out of the order they were created, so the highest id is not the highest
     * number. Codes are fixed-width and zero-padded, so lexical order IS
     * numeric order.
     *
     * Parsed rather than cast — see SequenceCode. `(int) substr($no, 3) + 1`
     * answers 1 for anything it cannot read, JE-000001 already exists, and the
     * unique index would then reject every post from that moment on.
     */
    private function allocateJournalNo(): string
    {
        $last = AccountingJournal::query()
            ->whereNotNull('journal_no')
            ->orderByDesc('journal_no')
            ->lockForUpdate()
            ->first();

        return $last === null
            ? SequenceCode::first(AccountingJournal::CODE_PREFIX)
            : SequenceCode::after(
                AccountingJournal::CODE_PREFIX,
                $last->journal_no,
                "accounting_journals.id {$last->id}",
            );
    }

    /**
     * Every account on the entry must still accept postings.
     *
     * Checked against the chart as it is NOW. A group heading double-counts its
     * own subtree, and a deactivated account is one an administrator has said
     * must take no new history — both are conditions that can arrive after a
     * draft was written and perfectly validated.
     *
     * @param  Collection<int, AccountingJournalLine>  $lines
     */
    private function assertEveryAccountIsPostable($lines, bool $allowInactive = false): void
    {
        $accounts = AccountingAccount::query()
            ->whereIn('id', $lines->pluck('accounting_account_id')->unique()->all())
            ->get()
            ->keyBy('id');

        foreach ($lines as $line) {
            $account = $accounts->get($line->accounting_account_id);

            if ($account === null) {
                throw ValidationException::withMessages([
                    'lines' => ["Line {$line->line_no} refers to an account that no longer exists."],
                ]);
            }

            if (AccountRules::isPostable($account)) {
                continue;
            }

            // Reversals tolerate a DEACTIVATED account; see self::reverse().
            // A heading is refused either way, because its balance is the sum
            // of its subtree and a line against it double-counts regardless of
            // why the line was written.
            if ($allowInactive && ! $account->is_group) {
                continue;
            }

            throw ValidationException::withMessages([
                'lines' => [
                    $account->is_group
                        ? "{$account->code} {$account->name} is a heading — post to one of its sub-accounts instead."
                        : "{$account->code} {$account->name} is inactive and cannot take new entries.",
                ],
            ]);
        }
    }

    /**
     * No single line may exceed what the module's arithmetic stays exact at.
     *
     * ## This MUST run before the totals are summed
     *
     * `debit`/`credit` are UNSIGNED BIGINT, so a value well past
     * {@see Money::maxCentavos()} stores happily — and {@see Money::sum()}
     * routes each value through a float, which ROUNDS such a value back DOWN to
     * roughly the bound. So by the time the total exists, the evidence is gone:
     * a line of maxCentavos + 1 sums to exactly maxCentavos, the total check
     * finds nothing wrong, and the entry posts with a header a centavo away
     * from its own lines. Nothing joins the two to notice.
     *
     * Reading the persisted line values directly, under the lock already held,
     * is the only place the real figure is still visible.
     *
     * @param  Collection<int, AccountingJournalLine>  $lines
     */
    /**
     * Cap the line count so the summed total stays an exact integer.
     *
     * Every line is already <= Money::maxCentavos() (2^53), but PHP_INT_MAX is
     * ~2^63 — so roughly 1024 lines at the cap overflow the integer range.
     * Money::sum() accumulates with `+=`, flips to float on overflow, and is
     * declared `: int`, so the ceiling check further down would then measure a
     * wrapped value rather than the real total, and a header could be posted
     * that disagrees with its own lines.
     *
     * MAX_LINES * maxCentavos() is ~4.5e18, comfortably inside PHP_INT_MAX, so
     * the sum below is always exact. Mirrors the `max:500` on the request, and
     * is repeated here because postImmediately() — the automatic posting path —
     * never passes through a FormRequest.
     */
    private function assertTheLineCountCannotOverflowTheSum($lines): void
    {
        if ($lines->count() <= self::MAX_LINES) {
            return;
        }

        throw ValidationException::withMessages([
            'lines' => [
                'An entry may carry at most '.self::MAX_LINES.' lines.',
            ],
        ]);
    }

    private function assertEveryAmountIsPlausible($lines): void
    {
        $max = Money::maxCentavos();

        foreach ($lines as $line) {
            $amount = max((int) $line->debit, (int) $line->credit);

            if ($amount <= $max) {
                continue;
            }

            throw ValidationException::withMessages([
                'lines' => [
                    "Line {$line->line_no} is ".Money::format($amount).', which is beyond any amount this '
                    .'system records exactly. Check the figures — an amount that size is a typo or a unit '
                    .'error, not a balance.',
                ],
            ]);
        }
    }

    /**
     * Persists a set of lines, numbering them from 1 in the order given.
     *
     * @param  list<array{account_id:int,description?:string|null,debit?:int,credit?:int}>  $lines
     */
    private function writeLines(AccountingJournal $journal, array $lines): void
    {
        $lineNo = 0;

        foreach ($lines as $line) {
            $lineNo++;

            $debit = (int) ($line['debit'] ?? 0);
            $credit = (int) ($line['credit'] ?? 0);

            // The same rule the database's CHECK enforces, refused here so it
            // reaches a screen as a 422 instead of as a driver error. Both
            // spellings of "wrong" are covered: a line that is somehow both
            // sides at once, and one that records nothing at all.
            if (($debit === 0) === ($credit === 0)) {
                throw ValidationException::withMessages([
                    "lines.{$lineNo}" => [
                        $debit === 0
                            ? "Line {$lineNo} has no amount on either side."
                            : "Line {$lineNo} has both a debit and a credit — a line can only be one side.",
                    ],
                ]);
            }

            if ($debit < 0 || $credit < 0) {
                throw ValidationException::withMessages([
                    "lines.{$lineNo}" => ["Line {$lineNo} needs a positive amount — direction belongs to the column, not the sign."],
                ]);
            }

            AccountingJournalLine::create([
                'accounting_journal_id' => $journal->id,
                'accounting_account_id' => $line['account_id'],
                'line_no' => $lineNo,
                'description' => $line['description'] ?? null,
                'debit' => $debit,
                'credit' => $credit,
            ]);
        }
    }

    /**
     * The journal already written for this document and source, if any.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function existingPostingFor(array $attributes): ?AccountingJournal
    {
        $type = $attributes['postable_type'] ?? null;
        $id = $attributes['postable_id'] ?? null;
        $source = $attributes['source'] ?? null;

        if ($type === null || $id === null || $source === null) {
            return null;
        }

        return AccountingJournal::query()
            ->where('postable_type', $type)
            ->where('postable_id', $id)
            ->where('source', $source)
            ->first();
    }

    private function lock(int $journalId): AccountingJournal
    {
        $journal = AccountingJournal::query()->whereKey($journalId)->lockForUpdate()->first();

        if ($journal === null) {
            throw ValidationException::withMessages([
                'journal' => ['That journal entry no longer exists.'],
            ]);
        }

        return $journal;
    }

    private function immutableMessage(AccountingJournal $journal): string
    {
        $name = $journal->journal_no ?: 'This entry';

        return "{$name} is {$journal->status} and can no longer be changed. "
            .'Post a reversing entry instead — reversing leaves both halves on the record, which is what '
            .'makes the books auditable.';
    }

    /**
     * "Reversal of JE-000154 — Collection from Juan Dela Cruz (Reason: …)".
     *
     * Mirrors `buildReversal` in `@/lib/accounting/journal`, with the operator's
     * reason folded in because there is no column for it and losing it would
     * leave the most useful half of a reversal unrecorded. Clamped to the
     * column's 500 characters by cutting the ORIGINAL description rather than
     * the tail: the journal number and the reason are the parts a person reads
     * first, and truncating the whole string would drop the reason entirely on
     * a long original.
     */
    private function reversalDescription(AccountingJournal $original, ?string $reason): string
    {
        $reason = trim((string) $reason);
        $suffix = $reason === '' ? '' : " (Reason: {$reason})";
        $prefix = 'Reversal of '.($original->journal_no ?: "journal {$original->id}").' — ';

        $room = 500 - mb_strlen($prefix) - mb_strlen($suffix);
        $body = (string) $original->description;

        if ($room < 0) {
            return mb_substr($prefix.$suffix, 0, 500);
        }

        return $prefix.mb_substr($body, 0, $room).$suffix;
    }
}
