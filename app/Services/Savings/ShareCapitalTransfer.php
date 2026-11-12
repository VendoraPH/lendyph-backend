<?php

namespace App\Services\Savings;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\SavingsTransaction;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use App\Services\Accounting\AutomaticPoster;
use App\Services\Accounting\PeriodGuard;
use App\Services\AuditLogService;
use App\Services\Savings\Api\LargeTransactionAlert;
use App\Services\Savings\Api\Refuse;
use App\Services\Savings\Api\Teller;
use Illuminate\Validation\ValidationException;

/**
 * Savings to share capital (gap round S3): money moves from a member's
 * savings account to their share capital, inside one transaction.
 *
 * - **Who:** an active member only (never withdrawn, deceased, inactive or
 *   blacklisted), from an account in the member's own branch, so the journal
 *   stays in one branch's books.
 * - **From what:** an active account (Teller::assertCanPayOut: never dormant,
 *   frozen or closed) whose product allows transfers, within its maximum
 *   withdrawal (ProductRules::assertCanTransferOut), up to the available
 *   balance (balance less holds, pending outflows and the maintaining
 *   balance), read under the account lock.
 * - **Tiers:** an outflow. Up to tier 1 it posts at once; above it opens a
 *   `share_capital_transfer` approval that reserves the amount, carried out
 *   by Approvals\ShareCapitalTransferExecutor, which checks all of it again.
 * - **What it writes** ({@see self::post()}), the rows first and the journal
 *   last, as ShareCapitalService does: a `share_capital_transfer` debit on the
 *   savings account, a share capital credit naming it
 *   (`savings_transaction_id`, method `savings_transfer`, the savings row's
 *   reference as its reference number), then one journal on the savings row,
 *   Dr 2050 Savings Deposits / Cr 3060 Share Capital. The share capital row
 *   posts no journal of its own (AutomaticPoster::shareCapitalEntry refuses
 *   it). An audit row names both.
 * - **Never reversed** (Reverser refuses it in words): a mistake is corrected
 *   with a share capital entry taking the amount back out and a deposit of it
 *   into savings.
 *
 * Runs inside the caller's transaction (IdempotentWrite or the approval
 * desk), after the idempotency key and under the savings account lock; a
 * share capital reference collision rolls the whole write back and is tried
 * again by GeneratedNumberWrite.
 */
class ShareCapitalTransfer
{
    /** The share capital row's `method` for money that came from savings. */
    public const SHARE_CAPITAL_METHOD = 'savings_transfer';

    public function __construct(
        private readonly SavingsLocks $locks,
        private readonly SavingsLedger $ledger,
        private readonly AutomaticPoster $poster,
        private readonly ApprovalGate $gate,
        private readonly PeriodGuard $periods,
        private readonly Teller $teller,
        private readonly ProductRules $rules,
        private readonly LargeTransactionAlert $alert,
    ) {}

    /**
     * Post a transfer, or (above tier 1, or while the account is held after a
     * deposit reactivated it) open its approval.
     *
     * @param  array{amount: string, remarks?: string|null}  $data
     * @return array{transaction: SavingsTransaction|null, entry: ShareCapitalLedger|null, approval: SavingsApproval|null}
     *
     * @throws ValidationException
     */
    public function request(int $accountId, array $data, User $user): array
    {
        $account = $this->locks->account($accountId);
        $centavos = SavingsMath::centavos($data['amount']);
        $date = now()->toDateString();

        $this->assertCanTransfer($account, $centavos);
        $this->teller->assertAvailable($account, $centavos);

        if ($this->gate->needsApprovalFor($account, $centavos)) {
            $this->teller->assertNotBeforeLatest($account, $date);
            $this->periods->assertOpen($date, 'This transfer to share capital');

            return ['transaction' => null, 'entry' => null, 'approval' => $this->gate->open('share_capital_transfer', $centavos, [
                'account_id' => $account->id,
                'payload' => ['date' => $date, 'remarks' => $data['remarks'] ?? null],
            ], $user->id)];
        }

        return [...$this->post($account, $centavos, ['date' => $date, 'remarks' => $data['remarks'] ?? null], $user->id, null, $user->id), 'approval' => null];
    }

    /**
     * Everything a transfer must still meet, under the account lock: the
     * member, the branch, the account and the product's rules (not the money,
     * which the caller checks with what it reserves itself).
     *
     * @throws ValidationException
     */
    public function assertCanTransfer(SavingsAccount $account, int $centavos): void
    {
        $member = Borrower::query()->findOrFail($account->borrower_id);
        $name = $member->full_name;

        $problem = match ($member->status) {
            'active' => null,
            'withdrawn' => "{$name} has withdrawn from the cooperative, so nothing can be transferred to share capital.",
            'deceased' => "{$name} is recorded as deceased, so nothing can be transferred to share capital.",
            default => "{$name} is not an active member ({$member->status}), so nothing can be transferred to share capital.",
        };

        if ($problem !== null) {
            Refuse::field('account', $problem);
        }

        if ($member->branch_id !== $account->branch_id) {
            $branch = $member->branch_id === null ? 'none' : (Branch::query()->whereKey($member->branch_id)->value('name') ?? 'none');

            Refuse::field('account', "{$account->account_number} is in another branch than {$name}'s ({$branch}). A transfer to share capital stays in the member's own branch.");
        }

        $this->teller->assertCanPayOut($account);
        $this->rules->assertCanTransferOut($account, $centavos);
    }

    /**
     * Write the savings debit and the share capital credit, then the one
     * journal, and audit it. The money was checked by the caller. ₱500,000.00
     * or more sends the large-transaction alert, to everyone but the maker,
     * the poster and `$otherDeciders` (a first checker), as a withdrawal does.
     *
     * @param  array{date: string, remarks?: string|null}  $data
     * @param  list<int|null>  $otherDeciders
     * @return array{transaction: SavingsTransaction, entry: ShareCapitalLedger}
     *
     * @throws ValidationException
     */
    public function post(SavingsAccount $account, int $centavos, array $data, int $makerId, ?int $approvalId, int $posterId, array $otherDeciders = []): array
    {
        $row = $this->ledger->debit($account, 'share_capital_transfer', $centavos, [
            'date' => $data['date'],
            'method' => 'savings',
            'remarks' => $data['remarks'] ?? null,
            'approval_id' => $approvalId,
            'created_by' => $makerId,
        ]);

        $entry = ShareCapitalLedger::query()->create([
            'borrower_id' => $account->borrower_id,
            'savings_transaction_id' => $row->id,
            'date' => $data['date'],
            'description' => "Transfer from savings account {$account->account_number}",
            'debit' => 0,
            'credit' => SavingsMath::pesos($centavos),
            'method' => self::SHARE_CAPITAL_METHOD,
            'reference_number' => $row->reference,
            'created_by' => $makerId,
        ]);

        $this->poster->savingsTransaction($row, $posterId);

        AuditLogService::log(
            'savings_share_capital_transfer',
            $row,
            null,
            ['account_id' => $account->id, 'amount' => SavingsMath::pesos($centavos), 'share_capital_reference' => $entry->reference, 'approval_id' => $approvalId],
            Refuse::peso($centavos)." moved from savings account {$account->account_number} to share capital ({$row->reference}, share capital {$entry->reference}).",
            $posterId,
        );

        $this->alert->afterPosting($row, [$makerId, $posterId, ...$otherDeciders]);

        return ['transaction' => $row, 'entry' => $entry];
    }
}
