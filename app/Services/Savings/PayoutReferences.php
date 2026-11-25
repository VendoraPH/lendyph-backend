<?php

namespace App\Services\Savings;

use App\Models\SavingsTransaction;
use App\Models\TimeDeposit;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Savings\Api\Refuse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * A payout's reference, recorded after the transfer (finish run round 6).
 *
 * A withdrawal (the Member Portal's included: it posts through the Teller as
 * an ordinary withdrawal row), a closing payout, or a time deposit's payout at
 * maturity or on pre-termination sent by bank transfer, GCash or Maya is often
 * posted before its reference exists: the money is sent after the checker's
 * approval and any AMLA check. Its blank reference can be recorded afterwards:
 * - once, only while blank, never on a reversed row or a reversal;
 * - under the posting rules: trimmed, the same length, and no reuse on the
 *   same method in the cooperative (DuplicateReference, 422 in words);
 * - by a holder of the permission that posts that payout
 *   ({@see self::permissionFor()}), in the record's branch (404 otherwise, by
 *   the routes' scope);
 * - under the row lock (the account's, then the transaction's; or the
 *   placement's), re-checked there, so a race answers 409 in words.
 *
 * It moves no money and posts no journal. Each one writes a
 * `savings_payout_reference_recorded` audit row in words, old blank and new
 * reference.
 */
class PayoutReferences
{
    /** The permission that posts each kind of payout, so the one that records its reference. */
    public const PERMISSIONS = [
        'withdrawal' => 'savings:transact',
        'closing' => 'savings:close',
        'time_deposit_maturity' => 'savings:transact',
        'time_deposit_pretermination' => 'savings:close',
    ];

    /** What each kind reads as. */
    public const KIND_WORDS = [
        'withdrawal' => 'Withdrawal',
        'closing' => 'Closing payout',
        'time_deposit_maturity' => 'Time deposit payout at maturity',
        'time_deposit_pretermination' => 'Time deposit pre-termination payout',
    ];

    /** A method in words, as the refusals and the audit rows read it. */
    public const METHOD_WORDS = [
        'cash' => 'cash',
        'bank_transfer' => 'bank transfer',
        'gcash' => 'GCash',
        'maya' => 'Maya',
        'savings' => 'savings',
    ];

    /** The time deposit statuses that paid something out. */
    public const SETTLED_STATUSES = ['withdrawn', 'rolled_over', 'preterminated'];

    /** The longest reference each record holds, as its posting request allows. */
    public const TRANSACTION_MAX = 64;

    public const TIME_DEPOSIT_MAX = 100;

    public function __construct(
        private readonly SavingsLocks $locks,
        private readonly DuplicateReference $duplicates,
    ) {}

    /** The payout's kind: withdrawal, closing, time_deposit_maturity or time_deposit_pretermination; null for anything else. */
    public static function kindOf(SavingsTransaction|TimeDeposit $record): ?string
    {
        if ($record instanceof TimeDeposit) {
            return match ($record->status) {
                'withdrawn', 'rolled_over' => 'time_deposit_maturity',
                'preterminated' => 'time_deposit_pretermination',
                default => null,
            };
        }

        return in_array($record->type, SavingsTransaction::PAYOUT_REFERENCE_TYPES, true) && $record->reverses_transaction_id === null ? $record->type : null;
    }

    /** The permission that posts this payout, or null when it isn't one. */
    public static function permissionFor(SavingsTransaction|TimeDeposit $record): ?string
    {
        $kind = self::kindOf($record);

        return $kind === null ? null : self::PERMISSIONS[$kind];
    }

    /**
     * Whether `$user` may record this transaction's reference now (the
     * resources' `can_record_reference`). The row is one the caller already
     * sees, so its branch is theirs.
     */
    public function canRecordOnTransaction(?User $user, SavingsTransaction $row, ?bool $reversed = null): bool
    {
        $permission = self::permissionFor($row);

        return $user !== null && $permission !== null && $user->can($permission) && $row->takesPayoutReferenceAfterwards($reversed);
    }

    /** Whether `$user` may record this placement's payout reference now. */
    public function canRecordOnTimeDeposit(?User $user, TimeDeposit $deposit): bool
    {
        $permission = self::permissionFor($deposit);

        return $user !== null && $permission !== null && $user->can($permission) && self::timeDepositTakesIt($deposit);
    }

    /** A settled placement that paid money out by bank transfer, GCash or Maya, still without its reference. */
    public static function timeDepositTakesIt(TimeDeposit $deposit): bool
    {
        return in_array($deposit->status, self::SETTLED_STATUSES, true)
            && in_array($deposit->payout_method, SavingsTransaction::PAYOUT_REFERENCE_METHODS, true)
            && SavingsMath::centavos((string) ($deposit->settled_amount ?? '0')) > 0
            && trim((string) $deposit->payout_reference) === '';
    }

    /**
     * Refused in words (422 on `reference`) unless the row, as read, takes its
     * reference afterwards.
     *
     * @throws ValidationException
     */
    public function assertTransactionTakesIt(SavingsTransaction $row): void
    {
        if (self::kindOf($row) === null) {
            Refuse::field('reference', 'A reference can be recorded afterwards only on a withdrawal or a closing payout.');
        }

        $stored = trim((string) $row->external_reference);

        if ($stored !== '') {
            Refuse::field('reference', "{$row->reference} already has its reference ({$stored}). A recorded reference can't be changed.");
        }

        if (! in_array($row->method, SavingsTransaction::PAYOUT_REFERENCE_METHODS, true)) {
            Refuse::field('reference', "{$row->reference} was paid in ".(self::METHOD_WORDS[$row->method] ?? 'another way').'. Only a payout by bank transfer, GCash or Maya takes its reference afterwards.');
        }
    }

    /** @throws ValidationException */
    public function assertTimeDepositTakesIt(TimeDeposit $deposit): void
    {
        $number = $deposit->certificate_number;

        if (! in_array($deposit->status, self::SETTLED_STATUSES, true)) {
            Refuse::field('reference', "{$number} hasn't been paid out yet. Its payout reference is recorded when it's settled.");
        }

        $stored = trim((string) $deposit->payout_reference);

        if ($stored !== '') {
            Refuse::field('reference', "{$number} already has its payout reference ({$stored}). A recorded reference can't be changed.");
        }

        if (SavingsMath::centavos((string) ($deposit->settled_amount ?? '0')) === 0 || $deposit->payout_method === null) {
            Refuse::field('reference', "{$number} paid nothing out. Its whole amount was placed again.");
        }

        if ($deposit->payout_method === 'savings') {
            Refuse::field('reference', "{$number} was paid into the member's savings. Only a payout by bank transfer, GCash or Maya takes its reference afterwards.");
        }

        if (! in_array($deposit->payout_method, SavingsTransaction::PAYOUT_REFERENCE_METHODS, true)) {
            Refuse::field('reference', "{$number} was paid out in ".(self::METHOD_WORDS[$deposit->payout_method] ?? 'another way').'. Only a payout by bank transfer, GCash or Maya takes its reference afterwards.');
        }
    }

    /**
     * Record `$reference` on a withdrawal or closing payout. `$row` is the
     * caller's read; it is checked, then locked (account, then row) and
     * re-checked, and a change in between answers 409.
     *
     * @throws ValidationException
     */
    public function recordOnTransaction(SavingsTransaction $row, string $reference, User $user): SavingsTransaction
    {
        $this->assertInTransaction();
        $reference = trim($reference);
        $this->assertTransactionTakesIt($row);

        $this->locks->accounts([$row->account_id]);
        /** @var SavingsTransaction $locked */
        $locked = SavingsTransaction::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();

        if (trim((string) $locked->external_reference) !== '') {
            Refuse::conflict("Someone recorded the reference of {$locked->reference} a moment ago. Refresh to see it.");
        }

        // A reversal posts under the account lock held here, so this read is final.
        if ($locked->reversedBy()->exists()) {
            Refuse::field('reference', "{$locked->reference} was reversed, so no reference can be recorded on it.");
        }

        $this->duplicates->assertUnused($locked->method, $reference, 'reference');

        $locked->external_reference = $reference;
        $locked->save();
        $locked->loadMissing('account:id,account_number');

        AuditLogService::log(
            'savings_payout_reference_recorded',
            $locked,
            ['external_reference' => null],
            ['external_reference' => $reference],
            sprintf(
                'Reference "%s" recorded on %s %s (%s, %s) of savings account %s.',
                $reference,
                $locked->type === 'closing' ? 'closing payout' : 'withdrawal',
                $locked->reference,
                self::METHOD_WORDS[$locked->method],
                Refuse::peso(SavingsMath::centavos((string) $locked->debit)),
                $locked->account?->account_number,
            ),
            $user->id,
        );

        return $locked;
    }

    /**
     * Record `$reference` on a placement's payout, under the placement's lock,
     * re-checked there; a change in between answers 409.
     *
     * @throws ValidationException
     */
    public function recordOnTimeDeposit(TimeDeposit $deposit, string $reference, User $user): TimeDeposit
    {
        $this->assertInTransaction();
        $reference = trim($reference);
        $this->assertTimeDepositTakesIt($deposit);

        $locked = $this->locks->timeDeposit($deposit->id);

        if (trim((string) $locked->payout_reference) !== '') {
            Refuse::conflict("Someone recorded the payout reference of {$locked->certificate_number} a moment ago. Refresh to see it.");
        }

        $this->duplicates->assertUnused($locked->payout_method, $reference, 'reference');

        // Conditioned on the stored reference still being blank, and outside
        // model auditing: the audit row below is this change's only one.
        $written = DB::table('time_deposits')
            ->where('id', $locked->id)
            ->where(fn ($blank) => $blank->whereNull('payout_reference')->orWhereRaw("TRIM(`payout_reference`) = ''"))
            ->update(['payout_reference' => $reference, 'updated_at' => now()]);

        if ($written !== 1) {
            Refuse::conflict("Someone recorded the payout reference of {$locked->certificate_number} a moment ago. Refresh to see it.");
        }

        $locked->refresh();

        AuditLogService::log(
            'savings_payout_reference_recorded',
            $locked,
            ['payout_reference' => null],
            ['payout_reference' => $reference],
            sprintf(
                'Reference "%s" recorded on the %s payout of time deposit %s (%s, %s).',
                $reference,
                $locked->status === 'preterminated' ? 'pre-termination' : 'maturity',
                $locked->certificate_number,
                self::METHOD_WORDS[$locked->payout_method],
                Refuse::peso(SavingsMath::centavos((string) $locked->settled_amount)),
            ),
            $user->id,
        );

        return $locked;
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A payout reference is recorded only inside a transaction, under the row lock.');
        }
    }
}
