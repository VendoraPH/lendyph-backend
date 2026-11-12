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
use App\Services\Savings\ProductRules;
use App\Services\Savings\SavingsLedger;
use App\Services\Savings\SavingsLocks;
use App\Services\Savings\SavingsMath;
use App\Services\Savings\WithdrawalFee;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * The counter (design §2, §4): deposits, withdrawals and transfers between a
 * member's own accounts. Runs inside the caller's transaction (IdempotentWrite
 * opens it, after inserting the key: lock step 1), then locks the account rows
 * in one id-ordered statement (step 3), writes through SavingsLedger and posts
 * the journal last (step 7).
 *
 * - A deposit is taken on an active or dormant account (it reactivates a
 *   dormant one), never on a frozen or closed one, within the product's
 *   rules (ProductRules: allow-deposits, the deposit limits, the maximum
 *   balance).
 * - A withdrawal follows the product's withdrawal rules and the month's free
 *   count; beyond it an excess-withdrawal fee posts as a linked `fee` row,
 *   and the money available must cover the amount plus the fee.
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
        private readonly LargeTransactionAlert $alert,
        private readonly ProductRules $rules,
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

        $centavos = SavingsMath::centavos($data['amount']);
        $this->rules->assertCanDeposit($account, $centavos);

        $row = $this->ledger->credit($account, 'deposit', $centavos, $this->attributes($data, $user->id));
        $this->poster->savingsTransaction($row, $user->id);
        $this->alert->afterPosting($row, [$user->id]);

        return $row;
    }

    /**
     * Post a withdrawal, or (above tier 1) open its approval. Its
     * excess-withdrawal fee is decided here, once, counting only what came
     * before it in the month of its date; or, for a member's portal request
     * being paid, `$decided` is the decision stored when the member asked
     * (checked against the product's rule then, 409 when it changed), paid
     * at that fee or less (ProductRules::payoutFee()).
     *
     * @param  array{amount: string, date: string, payment_method: string, reference?: string|null, remarks?: string|null}  $data
     * @return array{transaction: SavingsTransaction|null, approval: SavingsApproval|null}
     *
     * @throws ValidationException
     */
    public function withdraw(int $accountId, array $data, User $user, ?WithdrawalFee $decided = null): array
    {
        $account = $this->locks->account($accountId);
        $centavos = SavingsMath::centavos($data['amount']);

        $this->assertCanPayOut($account);

        if ($decided === null) {
            $decided = $this->rules->decideWithdrawal($account, $centavos, $data['date']);
        } else {
            $this->rules->assertStillDecided($account, $centavos, $decided);
        }

        $needsApproval = $this->gate->needsApprovalFor($account, $centavos);

        // Paid now: charge the stored fee, or less if something counted
        // before it has fallen away. Waiting for a checker: keep it as stored.
        if (! $needsApproval && $decided->source !== null) {
            $decided = $decided->charging($this->rules->payoutFee($account, $decided));
        }

        $this->assertAvailable($account, $centavos + $decided->fee);

        if ($needsApproval) {
            $this->assertNotBeforeLatest($account, $data['date']);
            $this->periods->assertOpen($data['date'], 'This withdrawal');

            return ['transaction' => null, 'approval' => $this->gate->open('withdrawal', $centavos, [
                'account_id' => $account->id,
                'fee' => $decided,
                'payload' => [
                    'date' => $data['date'],
                    'payment_method' => $data['payment_method'],
                    'reference' => $data['reference'] ?? null,
                    'remarks' => $data['remarks'] ?? null,
                ],
            ], $user->id)];
        }

        return ['transaction' => $this->postWithdrawal($account, $centavos, $data, $decided, $user->id, null, $user->id), 'approval' => null];
    }

    /**
     * Write a withdrawal already checked, rules and money (by withdraw() or,
     * once approved, by the WithdrawalExecutor), and post its journal. It
     * charges the fee `$fee` decided at request: beyond the free withdrawals
     * the fee posts as its own `fee` row linked to the withdrawal, with its
     * own journal, in the same write. The withdrawal row keeps `counted_on`
     * and `requested_at`, the date its request counts in and when it was
     * asked for.
     *
     * @param  array{date: string, payment_method: string, reference?: string|null, remarks?: string|null}  $data
     * @param  list<int|null>  $otherDeciders  checkers who approved it before the poster (not alerted)
     *
     * @throws ValidationException
     */
    public function postWithdrawal(SavingsAccount $account, int $centavos, array $data, WithdrawalFee $fee, int $makerId, ?int $approvalId, int $posterId, array $otherDeciders = []): SavingsTransaction
    {
        $row = $this->ledger->debit($account, 'withdrawal', $centavos, [
            ...$this->attributes($data, $makerId),
            'approval_id' => $approvalId,
            'counted_on' => $fee->countedOn,
            'requested_at' => $fee->requestedAt,
        ]);
        $this->poster->savingsTransaction($row, $posterId);

        if ($fee->fee > 0) {
            $feeRow = $this->ledger->debit($account, 'fee', $fee->fee, [
                'date' => $data['date'],
                'remarks' => "Excess-withdrawal fee on {$row->reference}",
                'approval_id' => $approvalId,
                'fee_for_transaction_id' => $row->id,
                'created_by' => $makerId,
            ]);
            $this->poster->savingsTransaction($feeRow, $posterId);
        }

        $this->alert->afterPosting($row, [$makerId, $posterId, ...$otherDeciders]);

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
        $this->rules->assertCanTransferOut($from, $centavos);
        $this->gate->assertNoUncheckedOutflow($from);

        if ($to->borrower_id !== $from->borrower_id) {
            Refuse::field('to_account_id', "{$to->account_number} belongs to another member. A transfer goes only between the same member's own accounts.");
        }

        if ($to->branch_id !== $from->branch_id) {
            Refuse::field('to_account_id', "{$to->account_number} is in another branch. Transfers between branches are not allowed.");
        }

        if ($to->isClosed() || $to->status === SavingsAccount::STATUS_FROZEN) {
            Refuse::field('to_account_id', "{$to->account_number} is {$to->status} and can't receive a transfer.");
        }

        $this->rules->assertCanReceiveTransfer($to, $centavos);
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
     * Refuse, when a request is made, a date earlier than the account's latest
     * row: the ledger would refuse it when the approved request posts.
     *
     * @throws ValidationException
     */
    public function assertNotBeforeLatest(SavingsAccount $account, string $date): void
    {
        $latest = $this->ledger->latestDate($account->id);

        if ($latest !== null && $date < $latest) {
            Refuse::field('date', "{$account->account_number} already has a transaction dated "
                .CarbonImmutable::parse($latest)->format('M j, Y').'; this can\'t be dated earlier.');
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
