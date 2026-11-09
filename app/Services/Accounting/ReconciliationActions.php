<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use App\Models\AccountingJournal;
use App\Models\AccountingJournalLine;
use App\Models\AccountingReconciliation;
use App\Models\AccountingReconciliationLine;
use App\Services\AuditLogService;
use App\Services\GeneratedNumberWrite;
use App\Services\LoanWriteTransaction;
use Closure;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PDOException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Turning an unmatched statement line into the entry the books are missing,
 * and matching the two in the same step (Phase 2, Accounting round C, C5).
 *
 * Three actions, each writing through the recorder that owns its record:
 *
 * - expense: money out, as an expense paid from the reconciled account
 *   ({@see ExpenseRecorder::record()}, Dr expense / Cr reconciled);
 * - transfer: money between the reconciled account and another money account
 *   ({@see FundTransferRecorder::record()});
 * - suspense: money nobody can identify yet, parked in the `suspense` role's
 *   account ({@see JournalPoster::postImmediately()}, no postable).
 *
 * ## One transaction per action, the statement line locked first
 *
 * The line is read `FOR UPDATE` and re-checked before anything is written, so
 * a double click waits behind the first request and then finds the line
 * matched (409) rather than recording the same money twice. The UNIQUE index
 * on `matched_journal_line_id` is the backstop. The new record, the match and
 * the audit row commit together or not at all: a closed period, an unmapped
 * role or a wrong account rolls every one of them back.
 *
 * ## The allowance lock
 *
 * None of these actions touches 1200 Allowance for Credit Losses: the
 * reconciled and counter accounts are money accounts, the expense account is
 * an expense and suspense is a liability. Every writer that touches 1200 must
 * lock that account row before any other lock, and this class takes the
 * statement-line lock first. So if a future change lets one of these actions
 * post to 1200, it must take the allowance lock before the statement line.
 *
 * MONEY IS IN CENTAVOS. A statement line's amount is signed, positive for
 * money in.
 */
class ReconciliationActions
{
    use DetectsConcurrencyErrors, ResolvesPostingAccounts;

    public const ALREADY_MATCHED = 'This statement line is already matched';

    public const SUSPENSE_UNMAPPED = 'Map a Suspense account in Accounting Settings first';

    public const COLLISION = 'Another change to this reconciliation was saved at the same time. Reload and try again.';

    /** The UNIQUE index on accounting_reconciliation_lines.matched_journal_line_id. */
    private const MATCH_INDEX = 'acct_recon_lines_journal_line_unique';

    public function __construct(
        private ExpenseRecorder $expenses,
        private FundTransferRecorder $transfers,
        private JournalPoster $poster,
    ) {}

    /**
     * @param  array{expense_account_id: int, payee: string, description?: string|null}  $data
     * @return array{type: string, id: int, journal_id: int, journal_no: string}
     */
    public function recordAsExpense(
        AccountingReconciliation $reconciliation,
        int $lineId,
        array $data,
        ?int $userId,
    ): array {
        return $this->act($reconciliation, $lineId, $userId, 'expense', function (
            AccountingReconciliationLine $line,
            AccountingAccount $reconciled,
        ) use ($data, $userId): array {
            if ($line->amount > 0) {
                throw ValidationException::withMessages([
                    'line' => [
                        'This statement line is money in. Only money out can be recorded as an expense; record '
                        .'money in as a transfer or a suspense entry.',
                    ],
                ]);
            }

            $expense = $this->expenses->record([
                'date' => $line->date->toDateString(),
                'payee' => $data['payee'],
                'expense_account_id' => (int) $data['expense_account_id'],
                'amount' => -$line->amount,
                'payment_account_id' => $reconciled->id,
                'branch_id' => null,
                'reference' => $line->external_reference,
                'description' => $data['description'] ?? $line->description,
            ], $userId);

            return [$expense->id, (int) $expense->journal_id];
        });
    }

    /**
     * Money in moves from the counter account into the reconciled one; money
     * out moves the other way.
     *
     * @param  array{counter_account_id: int, description?: string|null}  $data
     * @return array{type: string, id: int, journal_id: int, journal_no: string}
     */
    public function recordAsTransfer(
        AccountingReconciliation $reconciliation,
        int $lineId,
        array $data,
        ?int $userId,
    ): array {
        return $this->act($reconciliation, $lineId, $userId, 'transfer', function (
            AccountingReconciliationLine $line,
            AccountingAccount $reconciled,
        ) use ($data, $userId): array {
            $counter = $this->requireMoneyAccount((int) $data['counter_account_id'], 'counter_account_id');

            if ($counter->id === $reconciled->id) {
                throw ValidationException::withMessages([
                    'counter_account_id' => [
                        'Pick the other account the money moved to or from. A transfer cannot move money into the '
                        .'same account it came from.',
                    ],
                ]);
            }

            $moneyIn = $line->amount > 0;

            $journal = $this->transfers->record([
                'date' => $line->date->toDateString(),
                'from_account_id' => $moneyIn ? $counter->id : $reconciled->id,
                'to_account_id' => $moneyIn ? $reconciled->id : $counter->id,
                'amount' => abs($line->amount),
                'description' => $data['description'] ?? mb_substr('Transfer from statement line: '.$line->description, 0, 500),
                'branch_id' => null,
                'reference' => $line->external_reference,
            ], $userId);

            return [$journal->id, $journal->id];
        });
    }

    /**
     * Money in: Dr reconciled / Cr suspense. Money out: Dr suspense / Cr
     * reconciled. Source `bank_transaction` on a bank account and
     * `cash_transaction` on any other money account.
     *
     * @param  array{description?: string|null}  $data
     * @return array{type: string, id: int, journal_id: int, journal_no: string}
     */
    public function recordToSuspense(
        AccountingReconciliation $reconciliation,
        int $lineId,
        array $data,
        ?int $userId,
    ): array {
        return $this->act($reconciliation, $lineId, $userId, 'journal', function (
            AccountingReconciliationLine $line,
            AccountingAccount $reconciled,
        ) use ($reconciliation, $data, $userId): array {
            $suspense = $this->suspenseAccount();

            $amount = abs($line->amount);
            $memo = $data['description'] ?? null;
            $moneyIn = $line->amount > 0;

            $journal = $this->poster->postImmediately(
                [
                    'date' => $line->date->toDateString(),
                    'source' => $reconciled->cash_kind === 'bank' ? 'bank_transaction' : 'cash_transaction',
                    'reference' => $line->external_reference ?? "REC-{$reconciliation->id}-{$line->id}",
                    'description' => mb_substr('Unidentified statement line: '.$line->description, 0, 500),
                    'branch_id' => null,
                    // No postable, as a manual entry has none. The locked
                    // statement line and its UNIQUE match are what make a
                    // double click post once.
                ],
                [
                    [
                        'account_id' => $moneyIn ? $reconciled->id : $suspense->id,
                        'description' => $memo,
                        'debit' => $amount,
                        'credit' => 0,
                    ],
                    [
                        'account_id' => $moneyIn ? $suspense->id : $reconciled->id,
                        'description' => $memo,
                        'debit' => 0,
                        'credit' => $amount,
                    ],
                ],
                $userId,
            );

            return [$journal->id, $journal->id];
        });
    }

    /**
     * The shared frame: lock and re-check the line, write the record, match
     * the line to the record's journal line on the reconciled account, audit.
     *
     * `$create` writes the record and returns [record id, journal id].
     *
     * @param  Closure(AccountingReconciliationLine, AccountingAccount): array{0: int, 1: int}  $create
     * @return array{type: string, id: int, journal_id: int, journal_no: string}
     */
    private function act(
        AccountingReconciliation $reconciliation,
        int $lineId,
        ?int $userId,
        string $type,
        Closure $create,
    ): array {
        try {
            return DB::transaction(function () use ($reconciliation, $lineId, $userId, $type, $create): array {
                $line = AccountingReconciliationLine::query()
                    ->whereKey($lineId)
                    ->lockForUpdate()
                    ->first();

                if ($line === null || $line->accounting_reconciliation_id !== $reconciliation->id) {
                    throw new NotFoundHttpException('Statement line not found.');
                }

                if ($line->matched_journal_line_id !== null) {
                    throw LoanWriteTransaction::conflict(self::ALREADY_MATCHED);
                }

                if ($line->amount === 0) {
                    throw ValidationException::withMessages([
                        'line' => ['This statement line has no amount, so there is nothing to record.'],
                    ]);
                }

                $this->assertInsideTheWindow($reconciliation, $line);

                $reconciled = $this->requireMoneyAccount($reconciliation->accounting_account_id, 'account_id');

                [$recordId, $journalId] = $create($line, $reconciled);

                $journal = AccountingJournal::query()->findOrFail($journalId);
                $journalLineId = $this->theLineOnTheReconciledAccount($journal, $reconciled);

                $this->assertLedgerLineIsAvailable($reconciliation, $line, $journalLineId);

                $line->matched_journal_line_id = $journalLineId;
                $line->save();

                $label = match ($type) {
                    'expense' => 'expense',
                    'transfer' => 'transfer',
                    default => 'suspense entry',
                };

                AuditLogService::log(
                    'updated',
                    $reconciliation,
                    ['statement_line_id' => $line->id, 'matched_journal_line_id' => null],
                    [
                        'statement_line_id' => $line->id,
                        'matched_journal_line_id' => $journalLineId,
                        'recorded_as' => $type,
                        'journal_id' => $journal->id,
                    ],
                    "Statement line {$line->id} recorded as {$label} {$journal->journal_no}",
                    $userId,
                );

                return [
                    'type' => $type,
                    'id' => $recordId,
                    'journal_id' => $journal->id,
                    'journal_no' => (string) $journal->journal_no,
                ];
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($e->index === self::MATCH_INDEX) {
                throw LoanWriteTransaction::conflict(self::ALREADY_MATCHED);
            }

            if (GeneratedNumberWrite::isCollision($e)) {
                throw LoanWriteTransaction::conflict(self::COLLISION);
            }

            throw $e;
        } catch (PDOException $e) {
            // Deadlocks and lock waits, mapped as LoanWriteTransaction does.
            if (! $this->causedByConcurrencyError($e)) {
                throw $e;
            }

            throw LoanWriteTransaction::conflict(self::COLLISION);
        }
    }

    /**
     * The record is dated the line's date, and a journal line outside the
     * reconciliation's dates can never be matched to it. A statement line may
     * sit up to five days past either end (value dating), so this is refused
     * before anything is written.
     */
    private function assertInsideTheWindow(AccountingReconciliation $reconciliation, AccountingReconciliationLine $line): void
    {
        $date = $line->date->toDateString();
        $start = $reconciliation->start_date->toDateString();
        $end = $reconciliation->end_date->toDateString();

        if ($date < $start || $date > $end) {
            throw ValidationException::withMessages([
                'line' => [
                    "This statement line is dated {$date}, outside the period being reconciled ({$start} to {$end}). "
                    .'An entry dated on it could not be matched here. Record it in the reconciliation for its own '
                    .'period.',
                ],
            ]);
        }
    }

    /** The suspense role's account, or the 422 the screen shows when it is unmapped. */
    private function suspenseAccount(): AccountingAccount
    {
        $accountId = AccountingAccountMapping::query()
            ->where('role', 'suspense')
            ->value('accounting_account_id');

        $account = $accountId === null ? null : AccountingAccount::query()->find($accountId);

        if ($account === null) {
            throw ValidationException::withMessages([
                'account_mapping' => [self::SUSPENSE_UNMAPPED],
            ]);
        }

        $this->assertPostable($account, 'account_mapping');

        return $account;
    }

    /**
     * Exactly one line of the new journal is on the reconciled account. The
     * three shapes guarantee it; anything else is a bug, never something to
     * match against.
     */
    private function theLineOnTheReconciledAccount(AccountingJournal $journal, AccountingAccount $reconciled): int
    {
        $ids = AccountingJournalLine::query()
            ->where('accounting_journal_id', $journal->id)
            ->where('accounting_account_id', $reconciled->id)
            ->pluck('id');

        if ($ids->count() !== 1) {
            throw new RuntimeException(
                "Journal {$journal->id} has {$ids->count()} lines on account {$reconciled->id}; expected exactly one.",
            );
        }

        return (int) $ids->first();
    }

    /**
     * The checks AccountingReconciliationController::assertLedgerLineIsAvailable()
     * makes on a manual match: the same account, inside the reconciliation's
     * dates, and not claimed by another statement line.
     */
    private function assertLedgerLineIsAvailable(
        AccountingReconciliation $reconciliation,
        AccountingReconciliationLine $statementLine,
        int $journalLineId,
    ): void {
        $ledgerLine = DB::table('accounting_journal_lines as l')
            ->join('accounting_journals as j', 'j.id', '=', 'l.accounting_journal_id')
            ->where('l.id', $journalLineId)
            ->select(['l.accounting_account_id', 'j.date'])
            ->first();

        if ($ledgerLine === null || (int) $ledgerLine->accounting_account_id !== $reconciliation->accounting_account_id) {
            throw new RuntimeException("Journal line {$journalLineId} is not on the reconciled account.");
        }

        $date = substr((string) $ledgerLine->date, 0, 10);

        if ($date < $reconciliation->start_date->toDateString() || $date > $reconciliation->end_date->toDateString()) {
            throw ValidationException::withMessages([
                'line' => ["The new entry is dated {$date}, outside the period being reconciled."],
            ]);
        }

        $claimedBy = AccountingReconciliationLine::query()
            ->where('matched_journal_line_id', $journalLineId)
            ->where('id', '!=', $statementLine->id)
            ->value('id');

        if ($claimedBy !== null) {
            throw LoanWriteTransaction::conflict(self::ALREADY_MATCHED);
        }
    }
}
