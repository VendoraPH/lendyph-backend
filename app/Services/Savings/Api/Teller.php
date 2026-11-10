<?php

namespace App\Services\Savings\Api;

use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\Accounting\AutomaticPoster;
use App\Services\Accounting\PeriodGuard;
use App\Services\Savings\ApprovalGate;
use App\Services\Savings\AvailableBalance;
use App\Services\Savings\SavingsLedger;
use App\Services\Savings\SavingsLocks;
use App\Services\Savings\SavingsMath;
use Illuminate\Validation\ValidationException;

/**
 * The counter (design §2, §4): deposits, withdrawals and transfers between a
 * member's own accounts. Runs inside the caller's transaction (IdempotentWrite
 * opens it, after inserting the key: lock step 1), then locks the account rows
 * in one id-ordered statement (step 3), writes through SavingsLedger and posts
 * the journal last (step 7).
 *
 * - A deposit is taken on an active or dormant account (it reactivates a
 *   dormant one), never on a frozen or closed one.
 * - A withdrawal leaves only an active account, up to its available balance
 *   (AvailableBalance, read under the lock, so it is exact). Above tier 1 it
 *   opens an approval instead, which reserves the amount at once.
 * - A transfer goes between the same member's accounts in the same branch,
 *   to an active or dormant account (which it reactivates), with no journal
 *   (both legs sit on savings deposits) and no tier.
 */
class Teller
{
    public function __construct(
        private readonly SavingsLocks $locks,
        private readonly SavingsLedger $ledger,
        private readonly AutomaticPoster $poster,
        private readonly AvailableBalance $available,
        private readonly ApprovalGate $gate,
        private readonly PeriodGuard $periods,
    ) {}

    /**
     * @param  array{amount: string, date: string, payment_method: string, reference?: string|null, remarks?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function deposit(int $accountId, array $data, User $user): SavingsTransaction
    {
        $account = $this->locks->account($accountId);

        if ($account->isClosed()) {
            Refuse::field('account', "{$account->account_number} is closed and takes no deposits.");
        }

        if ($account->status === SavingsAccount::STATUS_FROZEN) {
            Refuse::field('account', "{$account->account_number} is frozen. Unfreeze it before taking a deposit.");
        }

        $row = $this->ledger->credit($account, 'deposit', SavingsMath::centavos($data['amount']), $this->attributes($data, $user->id));
        $this->poster->savingsTransaction($row, $user->id);

        return $row;
    }

    /**
     * Post a withdrawal, or (above tier 1) open its approval.
     *
     * @param  array{amount: string, date: string, payment_method: string, reference?: string|null, remarks?: string|null}  $data
     * @return array{transaction: SavingsTransaction|null, approval: SavingsApproval|null}
     *
     * @throws ValidationException
     */
    public function withdraw(int $accountId, array $data, User $user): array
    {
        $account = $this->locks->account($accountId);
        $centavos = SavingsMath::centavos($data['amount']);

        $this->assertCanPayOut($account);
        $this->assertAvailable($account, $centavos);

        if ($this->gate->needsApproval($centavos)) {
            $this->periods->assertOpen($data['date'], 'This withdrawal');

            return ['transaction' => null, 'approval' => $this->gate->open('withdrawal', $centavos, [
                'account_id' => $account->id,
                'payload' => [
                    'date' => $data['date'],
                    'payment_method' => $data['payment_method'],
                    'reference' => $data['reference'] ?? null,
                    'remarks' => $data['remarks'] ?? null,
                ],
            ], $user->id)];
        }

        return ['transaction' => $this->postWithdrawal($account, $centavos, $data, $user->id, null, $user->id), 'approval' => null];
    }

    /**
     * Write a withdrawal already checked (by withdraw() or, once approved, by
     * the WithdrawalExecutor) and post its journal.
     *
     * @param  array{date: string, payment_method: string, reference?: string|null, remarks?: string|null}  $data
     */
    public function postWithdrawal(SavingsAccount $account, int $centavos, array $data, int $makerId, ?int $approvalId, int $posterId): SavingsTransaction
    {
        $row = $this->ledger->debit($account, 'withdrawal', $centavos, [
            ...$this->attributes($data, $makerId),
            'approval_id' => $approvalId,
        ]);
        $this->poster->savingsTransaction($row, $posterId);

        return $row;
    }

    /**
     * @param  array{to_account_id: int|string, amount: string, date: string, remarks?: string|null}  $data
     * @return array{transfer_out: SavingsTransaction, transfer_in: SavingsTransaction}
     *
     * @throws ValidationException
     */
    public function transfer(int $fromId, array $data, User $user): array
    {
        $toId = (int) $data['to_account_id'];

        if ($toId === $fromId) {
            Refuse::field('to_account_id', 'Choose a different account to transfer to.');
        }

        $locked = $this->locks->accounts([$fromId, $toId]);
        $from = $locked->get($fromId) ?? abort(404, 'Savings account not found.');
        $to = $locked->get($toId) ?? Refuse::field('to_account_id', 'That savings account does not exist.');
        $centavos = SavingsMath::centavos($data['amount']);

        $this->assertCanPayOut($from);

        if ($to->borrower_id !== $from->borrower_id) {
            Refuse::field('to_account_id', "{$to->account_number} belongs to another member. A transfer goes only between the same member's own accounts.");
        }

        if ($to->branch_id !== $from->branch_id) {
            Refuse::field('to_account_id', "{$to->account_number} is in another branch. Transfers between branches are not allowed.");
        }

        if ($to->isClosed() || $to->status === SavingsAccount::STATUS_FROZEN) {
            Refuse::field('to_account_id', "{$to->account_number} is {$to->status} and can't receive a transfer.");
        }

        $this->assertAvailable($from, $centavos);

        $attributes = [
            'date' => $data['date'],
            'method' => 'savings',
            'remarks' => $data['remarks'] ?? null,
            'created_by' => $user->id,
        ];

        $out = $this->ledger->debit($from, 'transfer_out', $centavos, $attributes);
        $in = $this->ledger->credit($to, 'transfer_in', $centavos, [...$attributes, 'counterpart_transaction_id' => $out->id]);

        // Posts nothing (both legs sit on savings deposits); it refuses a leg
        // with no other leg, or legs in two branches.
        $this->poster->savingsTransaction($in, $user->id);

        return ['transfer_out' => $out, 'transfer_in' => $in];
    }

    /**
     * Only an active account pays money out.
     *
     * @throws ValidationException
     */
    public function assertCanPayOut(SavingsAccount $account): void
    {
        $message = match ($account->status) {
            SavingsAccount::STATUS_ACTIVE => null,
            SavingsAccount::STATUS_DORMANT => "{$account->account_number} is dormant. A deposit, or reactivating it, makes it active before money can leave it.",
            SavingsAccount::STATUS_FROZEN => "{$account->account_number} is frozen. Nothing can leave it until it is unfrozen.",
            default => "{$account->account_number} is closed.",
        };

        if ($message !== null) {
            Refuse::field('account', $message);
        }
    }

    /**
     * Refuse an amount above what can leave the account now: the balance less
     * active holds, pending outflows (less `$ownReserved`, what the request
     * being carried out reserves itself) and, unless `$keepMaintaining` is
     * false, the maintaining balance. Read under the lock.
     *
     * @throws ValidationException
     */
    public function assertAvailable(SavingsAccount $account, int $centavos, int $ownReserved = 0, bool $keepMaintaining = true): void
    {
        $b = $this->available->breakdown($account);
        $free = max(0, $b['ledger'] - $b['holds'] - max(0, $b['pending'] - $ownReserved) - ($keepMaintaining ? $b['maintaining'] : 0));

        if ($centavos > $free) {
            Refuse::field('amount', Refuse::peso($centavos).' is more than the '.Refuse::peso($free)." available on {$account->account_number}"
                .($keepMaintaining ? ' (balance less holds, requests waiting for approval and the maintaining balance).' : ' (balance less holds and requests waiting for approval).'));
        }
    }

    /**
     * @param  array{date: string, payment_method: string, reference?: string|null, remarks?: string|null}  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, int $makerId): array
    {
        return [
            'date' => $data['date'],
            'method' => $data['payment_method'],
            'external_reference' => $data['reference'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'created_by' => $makerId,
        ];
    }
}
