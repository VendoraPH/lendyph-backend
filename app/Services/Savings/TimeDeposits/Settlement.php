<?php

namespace App\Services\Savings\TimeDeposits;

use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\SavingsTransaction;
use App\Models\TimeDeposit;
use App\Models\User;
use App\Services\Accounting\AutomaticPoster;
use App\Services\AuditLogService;
use App\Services\Savings\ApprovalGate;
use App\Services\Savings\SavingsLedger;
use App\Services\Savings\SavingsLocks;
use App\Services\Savings\SavingsMath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Settling a time deposit at maturity, and breaking one early (design §2, §5).
 *
 * **At maturity** ({@see self::settle()}): on or after the maturity date, at
 * the contract's own figures plus, when settled late, post-maturity interest
 * from the maturity date to the settlement date ({@see Quote::postMaturity()}),
 * once only. `settled_interest` and `settled_tax` hold both together.
 * - `payout`: principal and net interest are paid out;
 * - `rollover_principal`: the principal is placed again, the net interest is
 *   paid out;
 * - `rollover_all`: principal and net interest (post-maturity included) are
 *   placed again.
 * A rollover is a new placement ({@see Placement::rollover()}) starting on
 * the settlement date, with the next instruction staff chose (else the old
 * one's).
 *
 * **Early** ({@see self::preterminate()}): only while the placement is still
 * running, before the maturity date, at the snapshotted regular rate for the
 * days held, no penalty in v1.
 *
 * **Paying out:** in cash, by bank, GCash or Maya, or into the member's own
 * savings account (`payout_method` savings): the one named at placement, or
 * any other of the member's accounts in the placement's branch that staff
 * pick at settlement (`payout_account_id`). One rule for both: a closed or
 * frozen account is refused; a dormant one receives it, and a staff payout
 * wakes it (member activity, through the ledger), while the nightly job's
 * doesn't. The settled placement then
 * records the account it was paid into; a rollover's new placement keeps the
 * account named at placement. A payout
 * through a money method above tier 1 waits for a checker (202, a
 * `td_payout` or `pretermination` approval, carried out by its executor);
 * a payout into savings moves no cash, like a transfer, and posts at once.
 *
 * **Once only:** the settling write is conditioned on the placement still
 * being open (409 when no row changes), on top of the placement's row lock;
 * a placement with any pending approval refuses a new request (409).
 *
 * **Books:** one `time_deposit_settlement` journal per placement, dated the
 * settlement date (`settled_on`). Its stored `settled_amount` is what was
 * paid out (₱0.00 on a full rollover), the figure AutomaticPoster checks the
 * journal against. Each settlement writes a `time_deposit_settled` audit row
 * (staff, or the system for the maturity job).
 *
 * **Locks:** the payout account first, then the placement, then the
 * approval row and the generated numbers, then the journal last.
 */
class Settlement
{
    /** Statuses that are still waiting to be settled. */
    public const OPEN_STATUSES = ['active', 'matured'];

    public function __construct(
        private readonly Placement $placement,
        private readonly Quote $quote,
        private readonly SavingsLedger $ledger,
        private readonly SavingsLocks $locks,
        private readonly ApprovalGate $gate,
        private readonly AutomaticPoster $poster,
    ) {}

    /**
     * A settlement asked for at the counter: carried out now (201), or waiting
     * for a checker (202).
     *
     * @param  array{date: string, action: string, payout_method?: string|null, payout_account_id?: int|null, next_instruction?: string|null}  $data
     * @return array{deposit: TimeDeposit, rolled: TimeDeposit|null, approval: SavingsApproval|null}
     *
     * @throws ValidationException
     */
    public function requestSettlement(TimeDeposit $deposit, array $data, User $user): array
    {
        $payoutMethod = $data['payout_method'] ?? null;
        $nextInstruction = $data['next_instruction'] ?? null;
        $pickedId = $payoutMethod === 'savings' && isset($data['payout_account_id']) ? (int) $data['payout_account_id'] : null;
        [$locked, $picked] = $this->lock($deposit, $payoutMethod === 'savings', $pickedId);

        $this->assertOpen($locked);
        $this->assertNoPendingApproval($locked);
        $this->assertSettlementDate($locked, $data['date']);

        $split = $this->split($locked, $data['action'], $data['date']);
        $paidOut = $split['paid_out'];

        if ($paidOut > 0) {
            $this->assertPayoutMethod($locked, $payoutMethod, $picked);

            if ($payoutMethod !== 'savings' && $this->gate->needsApproval($paidOut)) {
                $approval = $this->gate->open('td_payout', $paidOut, [
                    'time_deposit_id' => $locked->id,
                    'payload' => [
                        'date' => $data['date'],
                        'action' => $data['action'],
                        'payout_method' => $payoutMethod,
                        'next_instruction' => $nextInstruction,
                        'post_maturity_tax_rate' => $split['post_maturity']['tax_rate'],
                    ],
                ], $user->id);

                return ['deposit' => $locked, 'rolled' => null, 'approval' => $approval];
            }
        }

        $result = $this->settle($locked, $data['date'], $data['action'], $payoutMethod, $user->id, $nextInstruction, payoutAccount: $picked);

        return [...$result, 'approval' => null];
    }

    /**
     * Settle a matured placement, under its lock (and the payout account's,
     * when it pays into savings). `$userId` null is the system (the maturity
     * job). Re-checks what can have changed since a request.
     * `$postMaturityTaxRate` is the rate an approval snapshotted at request,
     * so a tax rule added since then never changes or refuses it.
     * `$payoutAccount` is the savings account staff picked to pay into, as
     * the caller locked it (else the one named at placement).
     *
     * @return array{deposit: TimeDeposit, rolled: TimeDeposit|null, credit: SavingsTransaction|null}
     *
     * @throws ValidationException
     */
    public function settle(TimeDeposit $locked, string $date, string $action, ?string $payoutMethod, ?int $userId, ?string $nextInstruction = null, ?string $postMaturityTaxRate = null, ?SavingsAccount $payoutAccount = null): array
    {
        $this->assertInTransaction();
        $this->assertOpen($locked);

        if ($date < $locked->maturity_date->toDateString()) {
            $this->refuse('date', $this->tooEarly($locked));
        }

        foreach ([$action, $nextInstruction ?? $action] as $instruction) {
            if (! in_array($instruction, TimeDeposit::MATURITY_INSTRUCTIONS, true)) {
                throw new LogicException("\"{$instruction}\" is not a maturity instruction.");
            }
        }

        ['gross' => $gross, 'tax' => $tax, 'rolled' => $rolled, 'paid_out' => $paidOut, 'post_maturity' => $postMaturity] = $this->split($locked, $action, $date, $postMaturityTaxRate);
        $method = $paidOut > 0 ? $payoutMethod : null;
        $into = $paidOut > 0 ? $this->assertPayoutMethod($locked, $method, $payoutAccount) : null;
        $named = $locked->payout_account_id;

        $before = $locked->status;
        $status = $action === 'payout' ? 'withdrawn' : 'rolled_over';
        $values = [
            'status' => $status,
            'settled_on' => $date,
            'settled_interest' => SavingsMath::pesos($gross),
            'settled_tax' => SavingsMath::pesos($tax),
            'settled_amount' => SavingsMath::pesos($paidOut),
            'payout_method' => $method,
            'settled_by' => $userId,
        ];

        $this->closeOnce($locked, self::OPEN_STATUSES, $values);

        $rolledInto = $rolled > 0 ? $this->placement->rollover($locked, $rolled, $userId, $date, $nextInstruction) : null;
        $credit = $into === null
            ? null
            : $this->creditSavings($locked, $into, $paidOut, $date, $userId, "Time deposit {$locked->certificate_number} matured");
        $moved = $this->recordPaidInto($locked, $into);

        $this->poster->timeDepositSettlement($locked, $rolled, $method, $userId);

        $words = match ($action) {
            'payout' => 'paid out '.$this->peso($paidOut),
            'rollover_principal' => "rolled over the principal into {$rolledInto?->certificate_number} and paid out ".$this->peso($paidOut).' interest',
            default => 'rolled over '.$this->peso($rolled)." into {$rolledInto?->certificate_number}",
        };

        AuditLogService::log(
            'time_deposit_settled',
            $locked,
            ['status' => $before, ...$this->accountChange($moved, $named)],
            [
                ...$values,
                ...$this->accountChange($moved, $into?->id),
                'post_maturity_days' => $postMaturity['days'],
                'post_maturity_interest' => SavingsMath::pesos($postMaturity['gross']),
                'rolled_over_amount' => SavingsMath::pesos($rolled),
                'rolled_over_to' => $rolledInto?->certificate_number,
            ],
            "Time deposit {$locked->certificate_number} settled on ".CarbonImmutable::parse($date)->format('M j, Y').": {$words}"
                .($into === null ? '' : " into savings account {$into->account_number}")
                .($postMaturity['days'] > 0 ? ", with {$this->peso($postMaturity['gross'])} post-maturity interest for {$postMaturity['days']} days" : '')
                .($userId === null ? ', by the system.' : '.'),
            $userId,
        );

        return ['deposit' => $locked, 'rolled' => $rolledInto, 'credit' => $credit];
    }

    /**
     * A pre-termination asked for at the counter: carried out now (201), or
     * waiting for a checker (202).
     *
     * @param  array{date: string, reason: string, payout_method: string, payout_account_id?: int|null}  $data
     * @return array{deposit: TimeDeposit, approval: SavingsApproval|null}
     *
     * @throws ValidationException
     */
    public function requestPretermination(TimeDeposit $deposit, array $data, User $user): array
    {
        $pickedId = $data['payout_method'] === 'savings' && isset($data['payout_account_id']) ? (int) $data['payout_account_id'] : null;
        [$locked, $picked] = $this->lock($deposit, $data['payout_method'] === 'savings', $pickedId);

        $this->assertCanPreterminate($locked);
        $this->assertNoPendingApproval($locked);
        $this->assertCounterDate($data['date']);

        $figures = $this->quote->pretermination($locked, $data['date']);
        $this->assertPayoutMethod($locked, $data['payout_method'], $picked);

        if ($data['payout_method'] !== 'savings' && $this->gate->needsApproval($figures['payout'])) {
            $approval = $this->gate->open('pretermination', $figures['payout'], [
                'time_deposit_id' => $locked->id,
                'payload' => ['date' => $data['date'], 'reason' => $data['reason'], 'payout_method' => $data['payout_method']],
            ], $user->id);

            return ['deposit' => $locked, 'approval' => $approval];
        }

        $this->preterminate($locked, $data['date'], $data['reason'], $data['payout_method'], $user->id, $picked);

        return ['deposit' => $locked, 'approval' => null];
    }

    /**
     * Break a placement early, under its lock: the quote's figures, paid out,
     * audited with the reason. `$payoutAccount` as for {@see self::settle()}.
     *
     * @return array{deposit: TimeDeposit, credit: SavingsTransaction|null, payout: int}
     *
     * @throws ValidationException
     */
    public function preterminate(TimeDeposit $locked, string $date, string $reason, string $payoutMethod, ?int $userId, ?SavingsAccount $payoutAccount = null): array
    {
        $this->assertInTransaction();
        $this->assertCanPreterminate($locked);

        $figures = $this->quote->pretermination($locked, $date);
        $into = $this->assertPayoutMethod($locked, $payoutMethod, $payoutAccount);
        $named = $locked->payout_account_id;

        $this->closeOnce($locked, ['active'], [
            'status' => 'preterminated',
            'settled_on' => $figures['date'],
            'settled_interest' => SavingsMath::pesos($figures['gross']),
            'settled_tax' => SavingsMath::pesos($figures['tax']),
            'settled_amount' => SavingsMath::pesos($figures['payout']),
            'payout_method' => $payoutMethod,
            'settled_by' => $userId,
        ]);

        $credit = $into === null
            ? null
            : $this->creditSavings($locked, $into, $figures['payout'], $figures['date'], $userId, "Time deposit {$locked->certificate_number} pre-terminated");
        $moved = $this->recordPaidInto($locked, $into);

        $day = CarbonImmutable::parse($figures['date'])->format('M j, Y');
        AuditLogService::log(
            'time_deposit_preterminated',
            $locked,
            ['status' => 'active', 'settled_on' => null, 'settled_amount' => null, ...$this->accountChange($moved, $named)],
            ['status' => 'preterminated', 'settled_on' => $figures['date'], 'settled_amount' => SavingsMath::pesos($figures['payout']), 'reason' => $reason, ...$this->accountChange($moved, $into?->id)],
            "Time deposit {$locked->certificate_number} pre-terminated on {$day}, {$figures['days_held']} days held, paying "
                .$this->peso($figures['payout']).($into === null ? '' : " into savings account {$into->account_number}").". Reason: {$reason}",
            $userId,
        );

        $this->poster->timeDepositSettlement($locked, 0, $payoutMethod, $userId);

        return ['deposit' => $locked, 'credit' => $credit, 'payout' => $figures['payout']];
    }

    /**
     * The principal, interest and tax of a settlement on $date (the contract's
     * plus any post-maturity interest), and how the amount due splits between
     * what rolls over and what is paid out.
     *
     * @return array{principal: int, gross: int, tax: int, rolled: int, paid_out: int, post_maturity: array{days: int, gross: int, tax: int, tax_rate: string|null}}
     *
     * @throws ValidationException
     */
    public function split(TimeDeposit $deposit, string $action, string $date, ?string $postMaturityTaxRate = null): array
    {
        $postMaturity = $this->quote->postMaturity($deposit, $date, $postMaturityTaxRate);
        $principal = SavingsMath::centavos($deposit->principal);
        $gross = SavingsMath::add(SavingsMath::centavos($deposit->gross_interest), $postMaturity['gross']);
        $tax = SavingsMath::centavos($deposit->withholding_tax) + $postMaturity['tax'];
        $due = $principal + $gross - $tax;

        $rolled = match ($action) {
            'rollover_all' => $due,
            'rollover_principal' => $principal,
            default => 0,
        };

        return ['principal' => $principal, 'gross' => $gross, 'tax' => $tax, 'rolled' => $rolled, 'paid_out' => $due - $rolled, 'post_maturity' => $postMaturity];
    }

    /**
     * Refuses a settlement date before maturity, after today, or before
     * yesterday (back-dating only until the next morning).
     *
     * @throws ValidationException
     */
    public function assertSettlementDate(TimeDeposit $deposit, string $date): void
    {
        if ($date < $deposit->maturity_date->toDateString()) {
            $this->refuse('date', $this->tooEarly($deposit));
        }

        $this->assertCounterDate($date);
    }

    /**
     * Refuses a placement already settled (409).
     */
    public function assertOpen(TimeDeposit $deposit): void
    {
        if (! in_array($deposit->status, self::OPEN_STATUSES, true)) {
            $this->alreadySettled($deposit);
        }
    }

    /**
     * Only a running placement can be broken early: one already settled is a
     * 409, a matured one a 422 (settle it instead).
     *
     * @throws ValidationException
     */
    public function assertCanPreterminate(TimeDeposit $deposit): void
    {
        $this->assertOpen($deposit);

        if ($deposit->status !== 'active') {
            $this->refuse('time_deposit', "{$deposit->certificate_number} has matured, so it can't be pre-terminated. Settle it instead.");
        }
    }

    /**
     * Refuses a payout method that can't take the money, and answers the
     * savings account a savings payout goes into (null for any other method).
     *
     * - Into an account staff picked (`$picked`, the locked row; the named
     *   one included when its id was sent): it must be the same member's and
     *   in the placement's branch. Refusals go on `payout_account_id`.
     * - Into the account named at placement: refused on `payout_method` when
     *   there is none.
     * Either way a closed or frozen account is refused; a dormant one can
     * receive it.
     *
     * @throws ValidationException
     */
    public function assertPayoutMethod(TimeDeposit $deposit, ?string $method, ?SavingsAccount $picked = null): ?SavingsAccount
    {
        if ($method === null) {
            $this->refuse('payout_method', 'Say how the payout leaves: cash, bank transfer, GCash, Maya or the member\'s savings account.');
        }

        if ($method !== 'savings') {
            return null;
        }

        if ($picked !== null) {
            return $this->pickedAccount($deposit, $picked);
        }

        if ($deposit->payout_account_id === null) {
            $this->refuse('payout_method', "{$deposit->certificate_number} names no savings account to pay into. Pick one of the member's savings accounts, or another payout method.");
        }

        $account = SavingsAccount::query()->findOrFail($deposit->payout_account_id);
        $this->placement->assertCanReceive($account, 'payout_method');

        return $account;
    }

    /**
     * Whether a payout or pre-termination of this placement waits for a
     * checker. Every `pending` row counts, past its `expires_at` too, until
     * the 00:30 `savings:expire` run (or a decision) closes it: an approval
     * that is still pending can still be carried out.
     */
    public function hasPendingApproval(TimeDeposit $deposit): bool
    {
        return SavingsApproval::query()
            ->where('time_deposit_id', $deposit->id)
            ->where('status', 'pending')
            ->exists();
    }

    /**
     * The one write that settles a placement: conditioned on its status still
     * being one of $from, so a second settlement changes no row and answers
     * 409, whatever lock a caller did or didn't take. No automatic audit row:
     * the caller writes the settlement's own.
     *
     * @param  list<string>  $from
     * @param  array<string, mixed>  $values
     */
    private function closeOnce(TimeDeposit $locked, array $from, array $values): void
    {
        $changed = TimeDeposit::query()
            ->whereKey($locked->id)
            ->whereIn('status', $from)
            ->update([...$values, 'updated_at' => now()]);

        $locked->refresh();

        if ($changed === 0) {
            $this->alreadySettled($locked);
        }
    }

    /**
     * A savings account staff picked to pay into, as locked: the member's
     * own, in the placement's branch, and not closed or frozen.
     *
     * @throws ValidationException
     */
    private function pickedAccount(TimeDeposit $deposit, SavingsAccount $account): SavingsAccount
    {
        if ($account->borrower_id !== $deposit->borrower_id) {
            $this->notTheMembers($deposit);
        }

        if ($account->branch_id !== $deposit->branch_id) {
            $this->refuse('payout_account_id', 'That savings account is in another branch. A time deposit can only move money within the member\'s own branch.');
        }

        $this->placement->assertCanReceive($account, 'payout_account_id');

        return $account;
    }

    /** @throws ValidationException */
    private function notTheMembers(TimeDeposit $deposit): never
    {
        $deposit->loadMissing('borrower');
        $this->refuse('payout_account_id', "That savings account isn't one of {$deposit->borrower?->full_name}'s.");
    }

    /**
     * The settled placement records the account it was paid into, when staff
     * picked one other than the account named at placement. Written after any
     * rollover, so the new placement keeps the named account. Whether it
     * changed.
     */
    private function recordPaidInto(TimeDeposit $locked, ?SavingsAccount $into): bool
    {
        if ($into === null || $into->id === $locked->payout_account_id) {
            return false;
        }

        TimeDeposit::query()->whereKey($locked->id)->update(['payout_account_id' => $into->id, 'updated_at' => now()]);
        $locked->refresh();

        return true;
    }

    /**
     * The payout account in the audit row's old or new values, by number,
     * only when the payout went to another account than the one named.
     *
     * @return array<string, mixed>
     */
    private function accountChange(bool $moved, ?int $accountId): array
    {
        if (! $moved) {
            return [];
        }

        return ['payout_account' => $accountId === null ? null : SavingsAccount::query()->whereKey($accountId)->value('account_number')];
    }

    /**
     * Lock the payout account (when it is paid into: the one staff picked,
     * else the one named at placement) and then the placement. Answers the
     * placement and the picked account's locked row (null when none was
     * picked); a picked id that doesn't exist is refused like another
     * member's account.
     *
     * @return array{0: TimeDeposit, 1: SavingsAccount|null}
     *
     * @throws ValidationException
     */
    private function lock(TimeDeposit $deposit, bool $intoSavings, ?int $pickedId = null): array
    {
        $this->assertInTransaction();

        $payoutAccountId = $intoSavings
            ? ($pickedId ?? DB::table('time_deposits')->where('id', $deposit->id)->value('payout_account_id'))
            : null;
        $accounts = $this->locks->accounts(array_filter([$payoutAccountId]));
        $locked = $this->locks->timeDeposit($deposit->id);
        $picked = $pickedId === null ? null : $accounts->get($pickedId);

        if ($pickedId !== null && $picked === null) {
            $this->notTheMembers($locked);
        }

        return [$locked, $picked];
    }

    private function assertNoPendingApproval(TimeDeposit $deposit): void
    {
        if ($this->hasPendingApproval($deposit)) {
            abort(409, "A payout of {$deposit->certificate_number} is already waiting for approval. Approve, reject or cancel it first.");
        }
    }

    private function alreadySettled(TimeDeposit $deposit): never
    {
        $on = $deposit->settled_on === null ? '' : ' on '.$deposit->settled_on->format('M j, Y');

        abort(409, "{$deposit->certificate_number} was already settled{$on} (".str_replace('_', ' ', $deposit->status).').');
    }

    /** @throws ValidationException */
    private function assertCounterDate(string $date): void
    {
        $today = CarbonImmutable::parse(now()->toDateString());

        if ($date > $today->toDateString()) {
            $this->refuse('date', "The date can't be after today ({$today->format('M j, Y')}).");
        }

        if ($date < $today->subDay()->toDateString()) {
            $this->refuse('date', 'The date can be back-dated only to yesterday ('.$today->subDay()->format('M j, Y').').');
        }
    }

    /**
     * Pay into the member's savings account. A payout by the nightly job (no
     * user) is not the member's doing: it doesn't count as their activity and
     * doesn't wake a dormant account. Staff paying it out at the counter does.
     */
    private function creditSavings(TimeDeposit $deposit, SavingsAccount $account, int $centavos, string $date, ?int $userId, string $remarks): SavingsTransaction
    {
        return $this->ledger->credit($account, 'deposit', $centavos, [
            'date' => $date,
            'method' => 'savings',
            'remarks' => $remarks,
            'time_deposit_id' => $deposit->id,
            'created_by' => $userId,
            'member_activity' => $userId !== null,
        ]);
    }

    private function tooEarly(TimeDeposit $deposit): string
    {
        return "{$deposit->certificate_number} matures on {$deposit->maturity_date->format('M j, Y')}. Before then, pre-terminate it instead.";
    }

    private function peso(int $centavos): string
    {
        return '₱'.number_format($centavos / 100, 2);
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A time deposit is settled only inside a transaction, under its lock.');
        }
    }

    /** @throws ValidationException */
    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
