<?php

namespace App\Services\Accounting;

use App\Models\AccountingExpense;
use App\Models\AccountingJournal;
use App\Models\AccountingJournalLine;
use App\Models\AccountingReconciliation;
use App\Models\AccountingReconciliationLine;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\BranchAccess;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * The bank reconciliation side of reversing an expense recorded from a
 * statement line (ReconciliationActions::recordAsExpense(), finish run round
 * 6). That expense keeps the line it came from (`reconciliation_line_id`),
 * and the line stays matched to the expense's own entry, which the reversal
 * undoes.
 *
 * A reconciliation has no stored state: it reads Reconciled while its
 * difference is ₱0.00 and every statement line is matched
 * (ReconciliationMatcher::present()); otherwise it is open. Reopening one is
 * unmatching a line on its worksheet.
 *
 * - **Open:** the reversal frees that line (unmatched again), with an audit
 *   row in words on the reconciliation, as the reconciliation actions write.
 * - **Reads Reconciled:** the reversal is refused in words until it's
 *   reopened, and nothing is posted.
 * - **All branches** (no branch): head office's. A user without
 *   `branches:view_all` would free a line on it, so their reversal is refused
 *   in words, nothing is posted, and they ask head office (finish run round
 *   7). A branch reconciliation is unchanged.
 *
 * Only that line. Any other match, a payment or an expense paired on the
 * worksheet, or the line paired with another entry since, is left alone: the
 * reversal is a new entry for its own statement line.
 *
 * ## Locks
 *
 * hold() runs inside the reversal's transaction, after ExpenseRecorder's own
 * locks (the branch row FOR SHARE, the expense row, then the journals and
 * every entry that reversed them, FOR UPDATE) and before JournalPoster's (the
 * period row FOR SHARE, the journal number). It locks every statement line of
 * the line's reconciliation, FOR UPDATE, in one read in id order, the order
 * the worksheet's match takes its lines in, so a match, an unmatch or a
 * reconciliation action on that worksheet waits behind the reversal, or the
 * reversal behind it. The line, its match and whether every line is matched
 * are then read from the locked rows, never from the snapshot the reversal
 * read before. Only the line's reconciliation id is read before the lock; a
 * line never moves to another reconciliation. A collision MySQL still breaks
 * with a deadlock answers 409 (BranchRowLock::transaction()).
 */
final class ReconciliationMatchRelease
{
    public function __construct(private ReconciliationMatcher $matcher, private BranchAccess $branches) {}

    /**
     * The statement line `$expense` was recorded from, locked, when it is
     * still matched to the expense's own entry or to `$journal` (the entry
     * the reversal undoes); null when there is none. Refused while its
     * reconciliation reads Reconciled, and, on an "all branches"
     * reconciliation, for a user (`$userId`) without `branches:view_all`.
     *
     * @throws ValidationException
     */
    public function hold(AccountingExpense $expense, AccountingJournal $journal, ?int $userId): ?AccountingReconciliationLine
    {
        $lineId = $expense->reconciliation_line_id;

        if ($lineId === null) {
            return null;
        }

        $reconciliationId = AccountingReconciliationLine::query()->whereKey($lineId)->value('accounting_reconciliation_id');

        if ($reconciliationId === null) {
            return null;
        }

        $lines = AccountingReconciliationLine::query()
            ->where('accounting_reconciliation_id', $reconciliationId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $line = $lines->firstWhere('id', $lineId);

        if ($line === null || $line->matched_journal_line_id === null) {
            return null;
        }

        $entries = array_values(array_unique(array_filter([$expense->journal_id, $journal->id])));
        $matchedHere = AccountingJournalLine::query()
            ->whereKey($line->matched_journal_line_id)
            ->whereIn('accounting_journal_id', $entries)
            ->exists();

        if (! $matchedHere) {
            return null;
        }

        $reconciliation = AccountingReconciliation::query()->findOrFail($reconciliationId);

        if ($reconciliation->branch_id === null && ! $this->seesAllBranches($userId)) {
            throw ValidationException::withMessages(['expense' => [self::headOfficesInWords($reconciliation)]]);
        }

        $everyLineMatched = $lines->every(static fn (AccountingReconciliationLine $row): bool => $row->matched_journal_line_id !== null);

        if ($everyLineMatched && $this->matcher->present($reconciliation)['difference'] === 0) {
            throw ValidationException::withMessages(['expense' => [self::reconciledInWords($reconciliation)]]);
        }

        return $line;
    }

    /**
     * Frees the held line once `$reversed` is reversed by `$reversal`, with an
     * audit row in words on its reconciliation.
     */
    public function release(?AccountingReconciliationLine $line, AccountingJournal $reversed, AccountingJournal $reversal, string $payee, ?int $userId): void
    {
        if ($line === null) {
            return;
        }

        $was = $line->matched_journal_line_id;
        $line->matched_journal_line_id = null;
        $line->save();

        AuditLogService::log(
            'updated',
            AccountingReconciliation::query()->findOrFail($line->accounting_reconciliation_id),
            ['statement_line_id' => $line->id, 'matched_journal_line_id' => $was],
            ['statement_line_id' => $line->id, 'matched_journal_line_id' => null],
            sprintf(
                'Statement line of %s for %s %s unmatched: its entry %s was reversed by %s when the expense to %s recorded from it was reversed on Expenses & Payables.',
                $line->date->format('M j, Y'),
                Money::format(abs($line->amount)),
                $line->amount < 0 ? 'out' : 'in',
                $reversed->journal_no,
                $reversal->journal_no,
                $payee,
            ),
            $userId,
        );
    }

    /**
     * Whether the user reversing holds `branches:view_all` (BranchAccess's
     * rule). No user means no.
     */
    private function seesAllBranches(?int $userId): bool
    {
        $user = $userId === null ? null : User::query()->find($userId);

        return $user !== null && $this->branches->for($user) === null;
    }

    /**
     * "This expense is matched on head office's all-branches reconciliation of
     * 1040 Bank Accounts for September 2026. Ask head office to reverse it."
     */
    private static function headOfficesInWords(AccountingReconciliation $reconciliation): string
    {
        return "This expense is matched on head office's all-branches reconciliation of "
            .self::accountWords($reconciliation).' for '.self::periodWords($reconciliation).'. Ask head office to reverse it.';
    }

    /**
     * "This expense is matched on the September 2026 reconciliation of 1040
     * Bank Accounts (All branches), which reads Reconciled. Reopen that
     * reconciliation first by unmatching its statement line there, then
     * reverse again."
     */
    private static function reconciledInWords(AccountingReconciliation $reconciliation): string
    {
        $reconciliation->loadMissing('branch:id,name');
        $period = self::periodWords($reconciliation);
        $account = self::accountWords($reconciliation);
        $branch = $reconciliation->branch_id === null ? ReconciliationMatcher::ALL_BRANCHES : (string) $reconciliation->branch?->name;

        return "This expense is matched on the {$period} reconciliation of {$account} ({$branch}), which reads Reconciled. "
            .'Reopen that reconciliation first by unmatching its statement line there, then reverse again.';
    }

    /** "September 2026", else the reconciliation's dates. */
    private static function periodWords(AccountingReconciliation $reconciliation): string
    {
        $period = trim((string) $reconciliation->period);

        if ($period !== '') {
            return $period;
        }

        return CarbonImmutable::parse($reconciliation->start_date)->format('M j, Y').' – '
            .CarbonImmutable::parse($reconciliation->end_date)->format('M j, Y');
    }

    /** "1040 Bank Accounts". */
    private static function accountWords(AccountingReconciliation $reconciliation): string
    {
        $reconciliation->loadMissing('account:id,code,name');

        return trim($reconciliation->account?->code.' '.$reconciliation->account?->name);
    }
}
