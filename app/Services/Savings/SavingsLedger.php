<?php

namespace App\Services\Savings;

use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\Accounting\PeriodGuard;
use App\Services\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * THE ONLY WRITER of `savings_transactions` (design §4, §5). Every deposit,
 * withdrawal, transfer leg, interest and tax row, closing payout and reversal
 * goes through {@see self::credit()} or {@see self::debit()}, inside the
 * caller's transaction, which this refuses to run outside.
 *
 * Under the account's row lock (re-taken here; already held by a caller that
 * locked through SavingsLocks, so it never waits twice) it:
 *
 * - refuses a closed account, member activity (deposits, withdrawals and
 *   transfers) on a frozen one, a zero amount, a debit that would take the
 *   balance below ₱0.00 (the available-balance guard is the caller's; this is
 *   the last line) and a balance beyond what the column holds;
 * - enforces the date rules: `date` is never after today (Manila), never
 *   before yesterday (back-dating only until the next morning, decision 8),
 *   never before the account opened, and never earlier than the account's
 *   latest row; `value_date` is never after `date` and never on or before
 *   `interest_credited_through` (a credited period is closed to new rows),
 *   except a valid `reversal` row (see assertReversal(): it undoes exactly
 *   one row, opposite side, same amount, once) that keeps the exact value
 *   date of the row it reverses, so an interest run can be reversed;
 * - pairs a transfer leg with one other leg only (`counterpart_transaction_id`);
 *   and the accounting period of `date` must be open (PeriodGuard, 422);
 * - writes the row with `balance_after`, then the `ledger_balance` cache and,
 *   for member activity (deposits, withdrawals and transfers), the
 *   `last_member_activity_on` date;
 * - reactivates a dormant account on member money in (a deposit or a
 *   transfer in), with an audit row;
 * - except for a row the member didn't make (`member_activity` false, such as
 *   a time deposit the nightly job pays into savings): it moves no
 *   `last_member_activity_on` and wakes no dormant account. It is refused on
 *   a frozen account and follows the date rules all the same.
 *
 * The journal is the caller's: post it through AutomaticPoster in the same
 * transaction, after this (lock step 7).
 */
class SavingsLedger
{
    /** decimal(12,2): the largest amount a row or a balance can hold, in centavos. */
    public const MAX_CENTAVOS = 999_999_999_999;

    /** Member-initiated money in, which wakes a dormant account. */
    public const REACTIVATING_TYPES = ['deposit', 'transfer_in'];

    public function __construct(
        private readonly SavingsNumbers $numbers,
        private readonly PeriodGuard $periods,
    ) {}

    /**
     * Add money to an account.
     *
     * @param  string  $type  deposit, transfer_in, interest or reversal
     * @param  array<string, mixed>  $attributes  date, value_date, method, external_reference, remarks, counterpart_transaction_id,
     *                                            reverses_transaction_id, time_deposit_id, interest_run_id, approval_id, fee_for_transaction_id
     *                                            (a fee row's withdrawal), counted_on (a withdrawal's request date, for the free
     *                                            withdrawals; defaults to `date`), requested_at (when its request was made), created_by, reference,
     *                                            member_activity. `date` defaults to today and `value_date` to `date`; `created_by` defaults
     *                                            to the signed-in user (pass null for the system); `reference` to the next SVT number;
     *                                            `member_activity` to true (false: the member didn't make this row, so it neither counts
     *                                            as their activity nor reactivates a dormant account).
     *
     * @throws ValidationException
     */
    public function credit(SavingsAccount $account, string $type, int $centavos, array $attributes = []): SavingsTransaction
    {
        return $this->post($account, $type, $centavos, 0, $attributes);
    }

    /**
     * Take money from an account.
     *
     * @param  string  $type  withdrawal, transfer_out, withholding_tax, closing, fee, share_capital_transfer or reversal
     * @param  array<string, mixed>  $attributes  as for {@see self::credit()}
     *
     * @throws ValidationException
     */
    public function debit(SavingsAccount $account, string $type, int $centavos, array $attributes = []): SavingsTransaction
    {
        return $this->post($account, $type, 0, $centavos, $attributes);
    }

    /** The latest business date on an account, or null before its first row. */
    public function latestDate(int $accountId): ?string
    {
        $latest = DB::table('savings_transactions')->where('account_id', $accountId)->max('date');

        return $latest === null ? null : CarbonImmutable::parse($latest)->toDateString();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function post(SavingsAccount $account, string $type, int $credit, int $debit, array $attributes): SavingsTransaction
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Savings transactions are written only inside a transaction, under the account lock.');
        }

        $this->assertTypeAndSide($type, $debit === 0);
        $amount = max($credit, $debit);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['The amount must be greater than ₱0.00.']]);
        }

        if ($amount > self::MAX_CENTAVOS) {
            throw ValidationException::withMessages(['amount' => ['The amount is beyond what a savings transaction can hold.']]);
        }

        $locked = SavingsAccount::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->isClosed()) {
            throw ValidationException::withMessages(['account' => ["{$locked->account_number} is closed and takes no more transactions."]]);
        }

        if ($locked->status === SavingsAccount::STATUS_FROZEN && in_array($type, SavingsTransaction::MEMBER_ACTIVITY_TYPES, true)) {
            throw ValidationException::withMessages(['account' => [
                "{$locked->account_number} is frozen: deposits, withdrawals and transfers are refused until it is unfrozen.",
            ]]);
        }

        $reversed = $this->assertReversal($locked, $type, $credit, $debit, $attributes);
        $this->assertCounterpart($attributes);

        [$date, $valueDate] = $this->assertDates($locked, $reversed, $attributes);
        $this->periods->assertOpen($date, 'This savings transaction');

        $before = SavingsMath::signedCentavos($locked->ledger_balance);
        $after = $before + $credit - $debit;

        if ($after < 0) {
            throw ValidationException::withMessages(['amount' => [
                "That would take {$locked->account_number} below ₱0.00: its balance is ".$this->peso($before).'.',
            ]]);
        }

        if ($after > self::MAX_CENTAVOS) {
            throw ValidationException::withMessages(['amount' => [
                "That would take {$locked->account_number} beyond ".$this->peso(self::MAX_CENTAVOS).', the most a savings balance can hold.',
            ]]);
        }

        $method = $attributes['method'] ?? null;

        if ($method !== null && ! in_array($method, SavingsTransaction::METHODS, true)) {
            throw ValidationException::withMessages(['method' => ['That payment method is not one savings accepts.']]);
        }

        $row = SavingsTransaction::query()->create([
            'account_id' => $locked->id,
            'reference' => $attributes['reference'] ?? $this->numbers->transactionReference($date),
            'type' => $type,
            'date' => $date,
            'value_date' => $valueDate,
            'debit' => SavingsMath::pesos($debit),
            'credit' => SavingsMath::pesos($credit),
            'balance_after' => SavingsMath::pesos($after),
            'method' => $method,
            'external_reference' => $attributes['external_reference'] ?? null,
            'remarks' => $attributes['remarks'] ?? null,
            'counterpart_transaction_id' => $attributes['counterpart_transaction_id'] ?? null,
            'reverses_transaction_id' => $attributes['reverses_transaction_id'] ?? null,
            'time_deposit_id' => $attributes['time_deposit_id'] ?? null,
            'interest_run_id' => $attributes['interest_run_id'] ?? null,
            'approval_id' => $attributes['approval_id'] ?? null,
            'fee_for_transaction_id' => $this->feeFor($locked, $type, $attributes),
            'counted_on' => $type === 'withdrawal' ? ($attributes['counted_on'] ?? $date) : null,
            'requested_at' => $type === 'withdrawal' ? ($attributes['requested_at'] ?? null) : null,
            'created_by' => array_key_exists('created_by', $attributes) ? $attributes['created_by'] : $this->signedInStaffId(),
        ]);

        $changes = ['ledger_balance' => SavingsMath::pesos($after), 'updated_at' => now()];
        $byMember = (bool) ($attributes['member_activity'] ?? true);

        if ($byMember && in_array($type, SavingsTransaction::MEMBER_ACTIVITY_TYPES, true)
            && ($locked->last_member_activity_on === null || $locked->last_member_activity_on->toDateString() < $date)) {
            $changes['last_member_activity_on'] = $date;
        }

        if ($byMember && in_array($type, self::REACTIVATING_TYPES, true) && $locked->status === SavingsAccount::STATUS_DORMANT) {
            $changes['status'] = SavingsAccount::STATUS_ACTIVE;
            $changes['dormant_since'] = null;
            $changes['reactivated_by_deposit_on'] = now()->toDateString();
        }

        DB::table('savings_accounts')->where('id', $locked->id)->update($changes);

        if (isset($changes['status'])) {
            AuditLogService::log(
                'savings_account_reactivated',
                $locked,
                ['status' => SavingsAccount::STATUS_DORMANT, 'dormant_since' => $locked->dormant_since?->toDateString()],
                ['status' => SavingsAccount::STATUS_ACTIVE, 'dormant_since' => null],
                "Savings account {$locked->account_number} reactivated by ".($type === 'deposit' ? 'deposit' : 'transfer in')." {$row->reference}.",
            );
        }

        $account->setRawAttributes(SavingsAccount::query()->findOrFail($locked->id)->getAttributes(), true);

        return $row;
    }

    private function assertTypeAndSide(string $type, bool $isCredit): void
    {
        if (! in_array($type, SavingsTransaction::TYPES, true)) {
            throw new LogicException("\"{$type}\" is not a savings transaction type.");
        }

        $allowed = $type === 'reversal'
            || ($isCredit && in_array($type, SavingsTransaction::CREDIT_TYPES, true))
            || (! $isCredit && in_array($type, SavingsTransaction::DEBIT_TYPES, true));

        if (! $allowed) {
            throw new LogicException("A {$type} row can't be a ".($isCredit ? 'credit' : 'debit').'.');
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: string, 1: string}
     *
     * @throws ValidationException
     */
    private function assertDates(SavingsAccount $account, ?SavingsTransaction $reversed, array $attributes): array
    {
        $today = CarbonImmutable::parse(now()->toDateString());
        $date = $this->dateOf($attributes['date'] ?? $today->toDateString(), 'date');
        $valueDate = $this->dateOf($attributes['value_date'] ?? $date->toDateString(), 'value_date');
        $opened = CarbonImmutable::parse($account->opened_on->toDateString());

        if ($date->greaterThan($today)) {
            $this->refuse('date', "The date can't be after today ({$this->day($today)}).");
        }

        if ($date->lessThan($today->subDay())) {
            $this->refuse('date', 'A transaction can be back-dated only to yesterday ('.$this->day($today->subDay()).'), not to '.$this->day($date).'.');
        }

        if ($date->lessThan($opened)) {
            $this->refuse('date', "{$account->account_number} was opened on {$this->day($opened)}; a transaction can't be dated before that.");
        }

        $latest = $this->latestDate($account->id);

        if ($latest !== null && $date->toDateString() < $latest) {
            $this->refuse('date', "{$account->account_number} already has a transaction dated ".$this->day(CarbonImmutable::parse($latest)).'; a new one can\'t be dated earlier.');
        }

        if ($valueDate->greaterThan($date)) {
            $this->refuse('value_date', "The value date can't be after the transaction date ({$this->day($date)}).");
        }

        if ($valueDate->lessThan($opened)) {
            $this->refuse('value_date', "The value date can't be before the account was opened ({$this->day($opened)}).");
        }

        $credited = $account->interest_credited_through;

        $keepsTheReversedValueDate = $reversed !== null && $reversed->value_date->toDateString() === $valueDate->toDateString();

        if ($credited !== null && $valueDate->toDateString() <= $credited->toDateString() && ! $keepsTheReversedValueDate) {
            $this->refuse('value_date', "Interest on {$account->account_number} has already been credited through {$this->day(CarbonImmutable::parse($credited->toDateString()))}, so nothing can be value-dated on or before it.");
        }

        return [$date->toDateString(), $valueDate->toDateString()];
    }

    /**
     * The row a reversal undoes, read under its own lock, or null for any
     * other row. A reversal must undo exactly one row (422 in words
     * otherwise):
     *
     * - it names the row (`reverses_transaction_id`), and only a reversal may;
     * - the row is on the same account and is not itself a reversal;
     * - it sits on the opposite side for exactly the same amount (in
     *   centavos): a credit undoes a debit, a debit undoes a credit;
     * - nothing has reversed the row yet. The unique index on
     *   `reverses_transaction_id` settles a race; this answers the ordinary
     *   case in words instead of a 500.
     *
     * Only a row that passes may keep the original's value date inside a
     * credited period (see assertDates()).
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    private function assertReversal(SavingsAccount $account, string $type, int $credit, int $debit, array $attributes): ?SavingsTransaction
    {
        $reverses = $attributes['reverses_transaction_id'] ?? null;

        if ($type !== 'reversal') {
            if ($reverses !== null) {
                $this->refuse('reverses_transaction_id', 'Only a reversal row can name a row it reverses.');
            }

            return null;
        }

        if ($reverses === null) {
            $this->refuse('reverses_transaction_id', 'A reversal must name the row it reverses.');
        }

        $original = SavingsTransaction::query()->whereKey($reverses)->lockForUpdate()->first();

        if ($original === null || $original->account_id !== $account->id) {
            $this->refuse('reverses_transaction_id', "The row being reversed isn't a transaction on this account ({$account->account_number}).");
        }

        if ($original->type === 'reversal') {
            $this->refuse('reverses_transaction_id', "{$original->reference} is a reversal, and a reversal can't itself be reversed.");
        }

        $originalCredit = SavingsMath::centavos($original->credit);
        $originalDebit = SavingsMath::centavos($original->debit);

        if (($credit > 0) !== ($originalDebit > 0)) {
            $this->refuse('reverses_transaction_id', "A reversal of {$original->reference} must sit on the opposite side: "
                .($originalCredit > 0 ? 'it added money, so its reversal takes it out.' : 'it took money out, so its reversal adds it back.'));
        }

        $expected = $originalCredit > 0 ? $originalCredit : $originalDebit;

        if (max($credit, $debit) !== $expected) {
            $this->refuse('amount', "A reversal of {$original->reference} must be for exactly ".$this->peso($expected).'.');
        }

        $existing = DB::table('savings_transactions')->where('reverses_transaction_id', $original->id)->value('reference');

        if ($existing !== null) {
            $this->refuse('reverses_transaction_id', "{$original->reference} has already been reversed by {$existing}.");
        }

        return $original;
    }

    /**
     * A transfer leg names the leg it pairs with (`counterpart_transaction_id`)
     * at most once: a row already claimed by another leg is refused (422). The
     * unique index on the column settles a race.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    private function assertCounterpart(array $attributes): void
    {
        $counterpart = $attributes['counterpart_transaction_id'] ?? null;

        if ($counterpart === null) {
            return;
        }

        $claimedBy = DB::table('savings_transactions')->where('counterpart_transaction_id', $counterpart)->value('reference');

        if ($claimedBy !== null) {
            $this->refuse('counterpart_transaction_id', "That transfer leg is already paired with {$claimedBy}.");
        }
    }

    /**
     * The withdrawal a `fee` row is charged on (gap round S1): a `fee` row
     * must name one withdrawal on the same account, and only a `fee` row may.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws LogicException
     */
    private function feeFor(SavingsAccount $account, string $type, array $attributes): ?int
    {
        $for = $attributes['fee_for_transaction_id'] ?? null;

        if ($type !== 'fee') {
            if ($for !== null) {
                throw new LogicException('Only a fee row names the withdrawal it was charged on.');
            }

            return null;
        }

        $withdrawal = $for === null ? null : DB::table('savings_transactions')->where('id', $for)->first(['account_id', 'type']);

        if ($withdrawal === null || (int) $withdrawal->account_id !== $account->id || $withdrawal->type !== 'withdrawal') {
            throw new LogicException('A fee row must name the withdrawal on its own account that it was charged on.');
        }

        return (int) $for;
    }

    private function dateOf(mixed $value, string $field): CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            $this->refuse($field, 'The date must be written as YYYY-MM-DD.');
        }

        return CarbonImmutable::parse($value)->startOfDay();
    }

    /** @throws ValidationException */
    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }

    private function day(CarbonImmutable $date): string
    {
        return $date->format('M j, Y');
    }

    private function peso(int $centavos): string
    {
        return ($centavos < 0 ? '-' : '').'₱'.number_format(abs($centavos) / 100, 2);
    }

    /**
     * The signed-in staff user's id, or null. Under the portal guard the
     * signed-in caller is a member (a PortalUser), whose id must never land in
     * `created_by`, a key to `users` (portal design §0, finding 5).
     */
    private function signedInStaffId(): ?int
    {
        $caller = auth()->user();

        return $caller instanceof User ? (int) $caller->getKey() : null;
    }
}
