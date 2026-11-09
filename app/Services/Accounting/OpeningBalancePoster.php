<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use App\Models\AccountingJournal;
use App\Models\AccountingOpeningBalance;
use App\Models\AccountingOpeningBalanceLine;
use App\Models\AccountingPeriod;
use App\Services\AuditLogService;
use App\Services\LoanWriteTransaction;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The one path an opening balance takes onto the books, for the CLI
 * (`accounting:opening-balances`) and for the screen's finalise alike.
 *
 * ## The lock order
 *
 * 1. The allowance account row (the `allowance_credit_losses` role, 1200 by
 *    default), when any line is on it. Every writer of allowance lines takes
 *    it before any journal, line, account or draft lock (see
 *    JournalPoster::lockTheAllowanceIfTouched() and the restructure
 *    write-off, J2). The CLI used to take the 3050 row and the `source` gap
 *    first and reach for 1200 inside postImmediately(), so an opening balance
 *    with a 1200 line and a J2 in flight could deadlock.
 * 2. The 3050 Opening Balance Equity row: the serialisation row both paths
 *    queue on (see the CLI's post() for why a real row and not the gap).
 * 3. The screen's draft row, through `$whileLocked` (the CLI has none).
 * 4. The periods, through lockThePeriods(): the month covering the date and
 *    every later month, under a shared lock. Before any opening-journal lock:
 *    a year-end close or reopen locks its months first and then inserts
 *    journals, so a run that held the `opening_balance` gap while it waited
 *    for the months could deadlock with it.
 * 5. The locking re-check for an `opening_balance` journal, current read.
 * 6. JournalPoster::postImmediately().
 *
 * Each path refuses the second opening balance, ever.
 */
class OpeningBalancePoster
{
    public const SOURCE = 'opening_balance';

    /** 3050 Opening Balance Equity, by code, as the CLI resolves it. */
    public const OPENING_EQUITY_CODE = '3050';

    public const REFERENCE = 'Opening balances';

    public const STALE = 'Someone else changed the opening balances; reload and try again.';

    private const ALLOWANCE_ROLE = 'allowance_credit_losses';

    public function __construct(
        private JournalPoster $poster,
        private PeriodGuard $periods,
    ) {}

    /**
     * Posts an opening balance inside the caller's transaction.
     *
     * `$attributes` carries the reference and description; the date, the
     * source and the (absent) branch are set here. `$whileLocked` runs once
     * the allowance and 3050 rows are held and before the re-check, which is
     * where the screen locks and checks its draft. It takes the period locks
     * itself, right after the draft's and before any journal lock, and
     * answers lockThePeriods()' refusal; without it, they are taken here.
     *
     * @param  list<array{account_id: int, debit: int, credit: int, description?: string|null}>  $lines
     * @param  array{reference?: string|null, description?: string|null}  $attributes
     * @param  (Closure(): ?ValidationException)|null  $whileLocked
     */
    public function post(
        array $lines,
        string $asOf,
        array $attributes,
        ?Model $postable,
        ?int $userId,
        ?Closure $whileLocked = null,
    ): AccountingJournal {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('OpeningBalancePoster::post() runs inside the caller\'s transaction, or its locks are released at once.');
        }

        $this->lockInOrder(array_column($lines, 'account_id'));

        $periodRefusal = $whileLocked !== null ? $whileLocked() : $this->lockThePeriods($asOf);

        $racing = $this->lockedOpeningJournal();

        if ($racing !== null) {
            throw ValidationException::withMessages([
                'source' => [
                    'Another run posted an opening balance while this one was waiting ('
                    .$this->journalLabel($racing).'). Nothing was written.',
                ],
            ]);
        }

        if ($periodRefusal !== null) {
            throw $periodRefusal;
        }

        return $this->poster->postImmediately(
            [
                'date' => $asOf,
                'source' => self::SOURCE,
                'reference' => $attributes['reference'] ?? null,
                'description' => $attributes['description'] ?? 'Opening balances as of '.$asOf,
                // The organisation's, not a branch's: see the CLI.
                'branch_id' => null,
                'postable_type' => $postable?->getMorphClass(),
                'postable_id' => $postable?->getKey(),
            ],
            $lines,
            $userId,
        );
    }

    /**
     * Steps 1 and 2 of the order: the allowance row when `$accountIds`
     * includes it, then the 3050 row. Answers 3050, or null when the chart
     * has none and `$requireOpeningEquity` is false; when it is true, a
     * missing 3050 is refused in words.
     *
     * @param  array<int, int|string|null>  $accountIds
     */
    public function lockInOrder(array $accountIds, bool $requireOpeningEquity = true): ?AccountingAccount
    {
        $allowance = AccountingAccountMapping::query()
            ->where('role', self::ALLOWANCE_ROLE)
            ->value('accounting_account_id');

        if ($allowance !== null && in_array((int) $allowance, array_map('intval', $accountIds), true)) {
            AccountingAccount::query()->whereKey((int) $allowance)->lockForUpdate()->first();
        }

        $equity = AccountingAccount::query()
            ->where('code', self::OPENING_EQUITY_CODE)
            ->lockForUpdate()
            ->first();

        if ($equity === null && $requireOpeningEquity) {
            throw ValidationException::withMessages([
                'account' => ['This chart has no '.self::OPENING_EQUITY_CODE.' Opening Balance Equity, so there is nowhere for the opening equity to go. Nothing was written.'],
            ]);
        }

        return $equity;
    }

    /**
     * The screen's finalise: posts the draft once, as one journal, only when
     * it balances. 409 when it was already finalised, an opening balance is
     * already on the books, or the draft changed since `$editVersion`; 422
     * when it doesn't balance or its period is closed.
     */
    public function finalise(AccountingOpeningBalance $draft, int $editVersion, int $userId): AccountingJournal
    {
        return LoanWriteTransaction::run(function () use ($draft, $editVersion, $userId): AccountingJournal {
            // Read without a lock, version first, so the lines are those of
            // that version: they decide on the allowance lock, which has to
            // come before the draft's. The version is re-checked under the
            // draft lock, and every save bumps it.
            $read = DB::table('accounting_opening_balances')->where('id', $draft->id)->first(['edit_version', 'as_of']);

            if ($read === null) {
                throw self::conflict(self::STALE);
            }

            $readVersion = (int) $read->edit_version;
            $lines = $this->linesOf($draft->id);
            $asOf = substr((string) $read->as_of, 0, 10);
            $locked = null;

            $journal = $this->post(
                array_map(static fn (array $line): array => $line + ['description' => 'Opening balance'], $lines),
                $asOf,
                ['reference' => self::REFERENCE, 'description' => 'Opening balances as of '.$asOf],
                $draft,
                $userId,
                function () use ($draft, $editVersion, $readVersion, $lines, $asOf, &$locked): ?ValidationException {
                    [$locked, $periodRefusal] = $this->assertFinalisable($draft->id, $editVersion, $readVersion, $lines, $asOf);

                    return $periodRefusal;
                },
            );

            $locked->forceFill([
                'status' => AccountingOpeningBalance::STATUS_FINALISED,
                'accounting_journal_id' => $journal->id,
                'finalised_by' => $userId,
                'finalised_at' => now(),
                'updated_by' => $userId,
                'edit_version' => $locked->edit_version + 1,
            ])->save();

            AuditLogService::log(
                'accounting_opening_balance_finalised',
                auditable: $locked,
                newValues: [
                    'journal_no' => $journal->journal_no,
                    'as_of' => $asOf,
                    'line_count' => count($lines),
                    // `_centavos` names, so the Audit Trail reads them as pesos.
                    'total_debit_centavos' => (int) $journal->total_debit,
                    'total_credit_centavos' => (int) $journal->total_credit,
                ],
                description: sprintf(
                    'Finalised the opening balances as of %s, posted as %s carrying %s',
                    $asOf,
                    $journal->journal_no,
                    Money::format((int) $journal->total_debit),
                ),
                userId: $userId,
            );

            return $journal;
        }, conflictMessage: self::STALE);
    }

    /** The first `opening_balance` journal, in any status, read without a lock. */
    public function openingJournal(): ?AccountingJournal
    {
        return AccountingJournal::query()->where('source', self::SOURCE)->orderBy('id')->first();
    }

    public function journalLabel(AccountingJournal $journal): string
    {
        return $journal->journal_no ?: 'draft #'.$journal->id;
    }

    public static function conflict(string $message): HttpResponseException
    {
        return LoanWriteTransaction::conflict($message);
    }

    /**
     * The draft, locked, then the period locks, and every reason the draft
     * can't post now. The periods' refusal is answered rather than thrown, so
     * post() refuses with it after its own re-check, as before.
     *
     * @param  list<array{account_id: int, debit: int, credit: int}>  $lines
     * @return array{0: AccountingOpeningBalance, 1: ValidationException|null}
     */
    private function assertFinalisable(int $draftId, int $editVersion, int $readVersion, array $lines, string $asOf): array
    {
        $locked = AccountingOpeningBalance::query()->whereKey($draftId)->lockForUpdate()->first();

        if ($locked === null) {
            throw self::conflict(self::STALE);
        }

        $periodRefusal = $this->lockThePeriods($asOf);

        if ($locked->isFinalised()) {
            // A locking read by id, so it is current: this transaction's
            // snapshot was taken before it queued behind the finalise that won.
            $journal = $locked->accounting_journal_id === null ? null : AccountingJournal::query()
                ->whereKey($locked->accounting_journal_id)
                ->sharedLock()
                ->first();
            $label = $journal === null ? '' : ' ('.$this->journalLabel($journal).')';

            throw self::conflict("The opening balances have already been finalised{$label}.");
        }

        $existing = $this->lockedOpeningJournal();

        if ($existing !== null) {
            throw self::conflict('An opening balance has already been posted ('.$this->journalLabel($existing).').');
        }

        if ($locked->edit_version !== $editVersion || $locked->edit_version !== $readVersion) {
            throw self::conflict(self::STALE);
        }

        $reason = self::balanceReason($lines);

        if ($reason !== null) {
            throw ValidationException::withMessages(['lines' => [$reason]]);
        }

        return [$locked, $periodRefusal];
    }

    /**
     * Step 4 of the order: takes the period locks for an opening balance
     * dated `$asOf` (the covering month through PeriodGuard, then every later
     * month, each under a shared lock) and answers why it can't be dated
     * there, on `as_of`, or null. Answered, not thrown, so each caller keeps
     * its own order of refusals while taking the locks before any
     * opening-journal lock.
     */
    public function lockThePeriods(string $asOf): ?ValidationException
    {
        try {
            $this->periods->assertOpen($asOf, 'The opening balances');
        } catch (ValidationException $e) {
            return ValidationException::withMessages(['as_of' => $e->errors()['date'] ?? $e->errors()]);
        }

        $laterClosed = $this->laterClosedReason($asOf, lock: true);

        return $laterClosed === null ? null : ValidationException::withMessages(['as_of' => [$laterClosed]]);
    }

    /**
     * Why `$asOf` can't take an opening balance because a later month is
     * already closed, or null. PeriodGuard only refuses the month covering
     * the date, and a month with no period row passes it; an opening balance
     * dated before signed-off months would still change their closing
     * figures. A year-end close closes all its months, so this covers closed
     * years too. With `$lock`, the later periods are read under a shared
     * lock, so a concurrent close waits for the posting to commit.
     */
    public function laterClosedReason(string $asOf, bool $lock = false): ?string
    {
        $periods = AccountingPeriod::query()->where('start_date', '>', $asOf);

        if ($lock) {
            $periods->sharedLock();
        }

        if (! $periods->get(['id', 'status'])->contains(fn (AccountingPeriod $period): bool => $period->isClosed())) {
            return null;
        }

        return sprintf(
            'Months after %s are already closed. Reopen them before recording opening balances.',
            Carbon::createFromFormat('!Y-m-d', $asOf)->format('j F Y'),
        );
    }

    /**
     * Why these lines can't post as they stand, or null when they balance.
     *
     * @param  list<array{debit: int, credit: int}>  $lines
     */
    public static function balanceReason(array $lines): ?string
    {
        if (count($lines) < 2) {
            return 'Add at least two lines.';
        }

        $difference = Money::sum(array_column($lines, 'debit')) - Money::sum(array_column($lines, 'credit'));

        if ($difference !== 0) {
            return sprintf(
                'The debits and credits differ by %s. Add the difference to %s Opening Balance Equity or correct a line.',
                Money::format(abs($difference)),
                self::OPENING_EQUITY_CODE,
            );
        }

        return null;
    }

    /**
     * The draft's lines in account code order, as journal lines.
     *
     * @return list<array{account_id: int, debit: int, credit: int}>
     */
    private function linesOf(int $draftId): array
    {
        return AccountingOpeningBalanceLine::query()
            ->join('accounting_accounts', 'accounting_accounts.id', '=', 'accounting_opening_balance_lines.accounting_account_id')
            ->where('accounting_opening_balance_lines.accounting_opening_balance_id', $draftId)
            ->orderBy('accounting_accounts.code')
            ->get(['accounting_opening_balance_lines.accounting_account_id', 'accounting_opening_balance_lines.debit', 'accounting_opening_balance_lines.credit'])
            ->map(static fn (AccountingOpeningBalanceLine $line): array => [
                'account_id' => (int) $line->accounting_account_id,
                'debit' => (int) $line->debit,
                'credit' => (int) $line->credit,
            ])
            ->all();
    }

    /**
     * The locking re-check: a current read, so a journal another transaction
     * committed while this one queued on 3050 is seen at either isolation
     * level.
     */
    private function lockedOpeningJournal(): ?AccountingJournal
    {
        return AccountingJournal::query()->where('source', self::SOURCE)->lockForUpdate()->first();
    }
}
