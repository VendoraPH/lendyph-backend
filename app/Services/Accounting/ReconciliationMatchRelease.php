<?php

namespace App\Services\Accounting;

use App\Models\AccountingJournal;
use App\Models\AccountingJournalLine;
use App\Models\AccountingReconciliation;
use App\Models\AccountingReconciliationLine;
use App\Services\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The bank reconciliation side of reversing an expense or one of its payments
 * from Expenses & Payables (finish run round 6). A statement line can be
 * matched to the entry being reversed: the expense's own journal when it was
 * recorded from the line (ReconciliationActions::recordAsExpense(), paid at
 * once from the reconciled account) or a payment's journal matched on the
 * worksheet. Once the entry is reversed, that line would stay matched to an
 * entry that no longer stands.
 *
 * A reconciliation has no stored state: it reads Reconciled while its
 * difference is ₱0.00 and every statement line is matched
 * (ReconciliationMatcher::present()'s `is_reconciled`); otherwise it is open.
 * Reopening one is unmatching a line on its worksheet.
 *
 * - **Open:** the reversal frees the line (unmatched again), with an audit row
 *   in words on the reconciliation, as the reconciliation actions write.
 * - **Reads Reconciled:** the reversal is refused in words until it's
 *   reopened, and nothing is posted.
 *
 * ## Locks
 *
 * hold() runs inside the reversal's transaction, after ExpenseRecorder's own
 * locks (the branch row FOR SHARE, the expense row, then the journals and
 * every entry that reversed them, FOR UPDATE) and before JournalPoster's (the
 * period row FOR SHARE, the journal number). It locks every statement line of
 * each reconciliation holding a match to the entry, FOR UPDATE, in one read in
 * reconciliation and id order, the order the worksheet's match takes its
 * lines in, so a match, an unmatch or a reconciliation action on the same
 * worksheet waits behind the reversal, or the reversal behind it, and whether
 * it reads Reconciled can't change before the commit. A collision MySQL still
 * breaks with a deadlock answers 409 (BranchRowLock::transaction()).
 */
final class ReconciliationMatchRelease
{
    public function __construct(private ReconciliationMatcher $matcher) {}

    /**
     * The statement lines matched to `$journal`'s lines, locked, or a refusal
     * when one sits on a reconciliation that reads Reconciled.
     *
     * @param  string  $field  the refusal's error key
     * @param  string  $subject  "expense" or "payment", for the refusal
     * @return Collection<int, AccountingReconciliationLine>
     *
     * @throws ValidationException
     */
    public function hold(AccountingJournal $journal, string $field, string $subject): Collection
    {
        $journalLineIds = AccountingJournalLine::query()->where('accounting_journal_id', $journal->id)->pluck('id')->all();

        if ($journalLineIds === []) {
            return new Collection;
        }

        $reconciliationIds = AccountingReconciliationLine::query()
            ->whereIn('matched_journal_line_id', $journalLineIds)
            ->pluck('accounting_reconciliation_id')
            ->unique()
            ->values()
            ->all();

        if ($reconciliationIds === []) {
            return new Collection;
        }

        $matched = AccountingReconciliationLine::query()
            ->whereIn('accounting_reconciliation_id', $reconciliationIds)
            ->orderBy('accounting_reconciliation_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->filter(static fn (AccountingReconciliationLine $line): bool => in_array($line->matched_journal_line_id, $journalLineIds, true))
            ->values();

        $reconciliations = AccountingReconciliation::query()
            ->whereKey($matched->pluck('accounting_reconciliation_id')->unique()->all())
            ->orderBy('id')
            ->get();

        foreach ($reconciliations as $reconciliation) {
            if ($this->matcher->present($reconciliation)['is_reconciled']) {
                throw ValidationException::withMessages([$field => [self::reconciledInWords($reconciliation, $subject)]]);
            }
        }

        return $matched;
    }

    /**
     * Frees the held lines once `$reversed` is reversed by `$reversal`, each
     * with an audit row in words on its reconciliation.
     *
     * @param  Collection<int, AccountingReconciliationLine>  $lines  from self::hold()
     * @param  string  $what  what was reversed, in words: "the expense to BDO"
     */
    public function release(Collection $lines, AccountingJournal $reversed, AccountingJournal $reversal, string $what, ?int $userId): void
    {
        foreach ($lines as $line) {
            $was = $line->matched_journal_line_id;
            $line->matched_journal_line_id = null;
            $line->save();

            AuditLogService::log(
                'updated',
                AccountingReconciliation::query()->findOrFail($line->accounting_reconciliation_id),
                ['statement_line_id' => $line->id, 'matched_journal_line_id' => $was],
                ['statement_line_id' => $line->id, 'matched_journal_line_id' => null],
                sprintf(
                    'Statement line of %s for %s %s unmatched: its entry %s was reversed by %s when %s was reversed on Expenses & Payables.',
                    $line->date->format('M j, Y'),
                    Money::format(abs($line->amount)),
                    $line->amount < 0 ? 'out' : 'in',
                    $reversed->journal_no,
                    $reversal->journal_no,
                    $what,
                ),
                $userId,
            );
        }
    }

    /**
     * "This expense is matched on the September 2026 reconciliation of 1040
     * Bank Accounts (All branches), which reads Reconciled. Reopen that
     * reconciliation first by unmatching its statement line there, then
     * reverse again."
     */
    private static function reconciledInWords(AccountingReconciliation $reconciliation, string $subject): string
    {
        $reconciliation->loadMissing(['account:id,code,name', 'branch:id,name']);
        $period = trim((string) $reconciliation->period);

        if ($period === '') {
            $period = CarbonImmutable::parse($reconciliation->start_date)->format('M j, Y').' – '
                .CarbonImmutable::parse($reconciliation->end_date)->format('M j, Y');
        }

        $account = trim($reconciliation->account?->code.' '.$reconciliation->account?->name);
        $branch = $reconciliation->branch_id === null ? ReconciliationMatcher::ALL_BRANCHES : (string) $reconciliation->branch?->name;

        return "This {$subject} is matched on the {$period} reconciliation of {$account} ({$branch}), which reads Reconciled. "
            .'Reopen that reconciliation first by unmatching its statement line there, then reverse again.';
    }
}
