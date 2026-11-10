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
 * the contract's own figures (interest only to maturity), once only.
 * - `payout`: principal and net interest are paid out;
 * - `rollover_principal`: the principal is placed again, the net interest is
 *   paid out;
 * - `rollover_all`: principal and net interest are placed again.
 * A rollover is a new placement ({@see Placement::rollover()}).
 *
 * **Early** ({@see self::preterminate()}): before the maturity date, at the
 * snapshotted regular rate for the days held, no penalty in v1.
 *
 * **Paying out:** in cash, by bank, GCash or Maya, or into the member's own
 * savings account named at placement (`payout_method` savings). A payout
 * through a money method above tier 1 waits for a checker (202, a
 * `td_payout` or `pretermination` approval, carried out by its executor);
 * a payout into savings moves no cash, like a transfer, and posts at once.
 *
 * **Books:** one `time_deposit_settlement` journal per placement. Its stored
 * `settled_amount` is what was paid out (₱0.00 on a full rollover), the
 * figure AutomaticPoster checks the journal against.
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
     * @param  array{date: string, action: string, payout_method?: string|null}  $data
     * @return array{deposit: TimeDeposit, rolled: TimeDeposit|null, approval: SavingsApproval|null}
     *
     * @throws ValidationException
     */
    public function requestSettlement(TimeDeposit $deposit, array $data, User $user): array
    {
        $payoutMethod = $data['payout_method'] ?? null;
        $locked = $this->lock($deposit, $payoutMethod === 'savings');

        $this->assertOpen($locked);
        $this->assertNoPendingApproval($locked);
        $this->assertSettlementDate($locked, $data['date']);

        $paidOut = $this->split($locked, $data['action'])['paid_out'];

        if ($paidOut > 0) {
            $this->assertPayoutMethod($locked, $payoutMethod);

            if ($payoutMethod !== 'savings' && $this->gate->needsApproval($paidOut)) {
                $approval = $this->gate->open('td_payout', $paidOut, [
                    'time_deposit_id' => $locked->id,
                    'payload' => ['date' => $data['date'], 'action' => $data['action'], 'payout_method' => $payoutMethod],
                ], $user->id);

                return ['deposit' => $locked, 'rolled' => null, 'approval' => $approval];
            }
        }

        $result = $this->settle($locked, $data['date'], $data['action'], $payoutMethod, $user->id);

        return [...$result, 'approval' => null];
    }

    /**
     * Settle a matured placement, under its lock (and the payout account's,
     * when it pays into savings). `$userId` null is the system (the maturity
     * job). Re-checks what can have changed since a request.
     *
     * @return array{deposit: TimeDeposit, rolled: TimeDeposit|null, credit: SavingsTransaction|null}
     *
     * @throws ValidationException
     */
    public function settle(TimeDeposit $locked, string $date, string $action, ?string $payoutMethod, ?int $userId): array
    {
        $this->assertInTransaction();
        $this->assertOpen($locked);

        if ($date < $locked->maturity_date->toDateString()) {
            $this->refuse('date', $this->tooEarly($locked));
        }

        if (! in_array($action, TimeDeposit::MATURITY_INSTRUCTIONS, true)) {
            throw new LogicException("\"{$action}\" is not a maturity instruction.");
        }

        ['gross' => $gross, 'tax' => $tax, 'rolled' => $rolled, 'paid_out' => $paidOut] = $this->split($locked, $action);
        $method = $paidOut > 0 ? $payoutMethod : null;

        if ($paidOut > 0) {
            $this->assertPayoutMethod($locked, $method);
        }

        $locked->forceFill([
            'status' => $action === 'payout' ? 'withdrawn' : 'rolled_over',
            'settled_on' => $date,
            'settled_interest' => SavingsMath::pesos($gross),
            'settled_tax' => SavingsMath::pesos($tax),
            'settled_amount' => SavingsMath::pesos($paidOut),
            'payout_method' => $method,
            'settled_by' => $userId,
        ])->save();

        $rolledInto = $rolled > 0 ? $this->placement->rollover($locked, $rolled, $userId) : null;
        $credit = $method === 'savings'
            ? $this->creditSavings($locked, $paidOut, $date, $userId, "Time deposit {$locked->certificate_number} matured")
            : null;

        $this->poster->timeDepositSettlement($locked, $rolled, $method, $userId);

        return ['deposit' => $locked, 'rolled' => $rolledInto, 'credit' => $credit];
    }

    /**
     * A pre-termination asked for at the counter: carried out now (201), or
     * waiting for a checker (202).
     *
     * @param  array{date: string, reason: string, payout_method: string}  $data
     * @return array{deposit: TimeDeposit, approval: SavingsApproval|null}
     *
     * @throws ValidationException
     */
    public function requestPretermination(TimeDeposit $deposit, array $data, User $user): array
    {
        $locked = $this->lock($deposit, $data['payout_method'] === 'savings');

        $this->assertOpen($locked);
        $this->assertNoPendingApproval($locked);
        $this->assertCounterDate($data['date']);

        $figures = $this->quote->pretermination($locked, $data['date']);
        $this->assertPayoutMethod($locked, $data['payout_method']);

        if ($data['payout_method'] !== 'savings' && $this->gate->needsApproval($figures['payout'])) {
            $approval = $this->gate->open('pretermination', $figures['payout'], [
                'time_deposit_id' => $locked->id,
                'payload' => ['date' => $data['date'], 'reason' => $data['reason'], 'payout_method' => $data['payout_method']],
            ], $user->id);

            return ['deposit' => $locked, 'approval' => $approval];
        }

        $this->preterminate($locked, $data['date'], $data['reason'], $data['payout_method'], $user->id);

        return ['deposit' => $locked, 'approval' => null];
    }

    /**
     * Break a placement early, under its lock: the quote's figures, paid out,
     * audited with the reason.
     *
     * @return array{deposit: TimeDeposit, credit: SavingsTransaction|null, payout: int}
     *
     * @throws ValidationException
     */
    public function preterminate(TimeDeposit $locked, string $date, string $reason, string $payoutMethod, ?int $userId): array
    {
        $this->assertInTransaction();
        $this->assertOpen($locked);

        if ($locked->status !== 'active') {
            $this->refuse('date', "{$locked->certificate_number} has matured, so it can't be pre-terminated. Settle it instead.");
        }

        $figures = $this->quote->pretermination($locked, $date);
        $this->assertPayoutMethod($locked, $payoutMethod);
        $before = $locked->only(['status', 'settled_on', 'settled_amount']);

        $locked->forceFill([
            'status' => 'preterminated',
            'settled_on' => $figures['date'],
            'settled_interest' => SavingsMath::pesos($figures['gross']),
            'settled_tax' => SavingsMath::pesos($figures['tax']),
            'settled_amount' => SavingsMath::pesos($figures['payout']),
            'payout_method' => $payoutMethod,
            'settled_by' => $userId,
        ])->saveQuietly();

        $day = CarbonImmutable::parse($figures['date'])->format('M j, Y');
        AuditLogService::log(
            'time_deposit_preterminated',
            $locked,
            ['status' => $before['status'], 'settled_on' => null, 'settled_amount' => null],
            ['status' => 'preterminated', 'settled_on' => $figures['date'], 'settled_amount' => SavingsMath::pesos($figures['payout']), 'reason' => $reason],
            "Time deposit {$locked->certificate_number} pre-terminated on {$day}, {$figures['days_held']} days held, paying "
                .'₱'.number_format($figures['payout'] / 100, 2).". Reason: {$reason}",
            $userId,
        );

        $credit = $payoutMethod === 'savings'
            ? $this->creditSavings($locked, $figures['payout'], $figures['date'], $userId, "Time deposit {$locked->certificate_number} pre-terminated")
            : null;

        $this->poster->timeDepositSettlement($locked, 0, $payoutMethod, $userId);

        return ['deposit' => $locked, 'credit' => $credit, 'payout' => $figures['payout']];
    }

    /**
     * The principal, interest and tax of a settlement at maturity, and how
     * the amount due splits between what rolls over and what is paid out.
     *
     * @return array{principal: int, gross: int, tax: int, rolled: int, paid_out: int}
     */
    public function split(TimeDeposit $deposit, string $action): array
    {
        $principal = SavingsMath::centavos($deposit->principal);
        $gross = SavingsMath::centavos($deposit->gross_interest);
        $tax = SavingsMath::centavos($deposit->withholding_tax);
        $due = $principal + $gross - $tax;

        $rolled = match ($action) {
            'rollover_all' => $due,
            'rollover_principal' => $principal,
            default => 0,
        };

        return ['principal' => $principal, 'gross' => $gross, 'tax' => $tax, 'rolled' => $rolled, 'paid_out' => $due - $rolled];
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
            $on = $deposit->settled_on === null ? '' : ' on '.$deposit->settled_on->format('M j, Y');

            abort(409, "{$deposit->certificate_number} was already settled{$on} (".str_replace('_', ' ', $deposit->status).').');
        }
    }

    /**
     * Refuses a payout method that can't take the money: into savings with no
     * payout account named at placement, or into one that is closed or frozen.
     *
     * @throws ValidationException
     */
    public function assertPayoutMethod(TimeDeposit $deposit, ?string $method): void
    {
        if ($method === null) {
            $this->refuse('payout_method', 'Say how the payout leaves: cash, bank transfer, GCash, Maya or the member\'s savings account.');
        }

        if ($method !== 'savings') {
            return;
        }

        if ($deposit->payout_account_id === null) {
            $this->refuse('payout_method', "{$deposit->certificate_number} names no savings account to pay into. Pick another payout method.");
        }

        $this->placement->assertCanReceive(SavingsAccount::query()->findOrFail($deposit->payout_account_id), 'payout_method');
    }

    /** Whether a payout or pre-termination of this placement waits for a checker. */
    public function hasPendingApproval(TimeDeposit $deposit): bool
    {
        return SavingsApproval::query()
            ->where('time_deposit_id', $deposit->id)
            ->where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }

    /** Lock the payout account (when it is paid into) and then the placement. */
    private function lock(TimeDeposit $deposit, bool $intoSavings): TimeDeposit
    {
        $this->assertInTransaction();

        $payoutAccountId = $intoSavings ? DB::table('time_deposits')->where('id', $deposit->id)->value('payout_account_id') : null;
        $this->locks->accounts(array_filter([$payoutAccountId]));

        return $this->locks->timeDeposit($deposit->id);
    }

    private function assertNoPendingApproval(TimeDeposit $deposit): void
    {
        if ($this->hasPendingApproval($deposit)) {
            abort(409, "A payout of {$deposit->certificate_number} is already waiting for approval.");
        }
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

    private function creditSavings(TimeDeposit $deposit, int $centavos, string $date, ?int $userId, string $remarks): SavingsTransaction
    {
        $account = SavingsAccount::query()->findOrFail($deposit->payout_account_id);

        return $this->ledger->credit($account, 'deposit', $centavos, [
            'date' => $date,
            'method' => 'savings',
            'remarks' => $remarks,
            'time_deposit_id' => $deposit->id,
            'created_by' => $userId,
        ]);
    }

    private function tooEarly(TimeDeposit $deposit): string
    {
        return "{$deposit->certificate_number} matures on {$deposit->maturity_date->format('M j, Y')}. Before then, pre-terminate it instead.";
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
