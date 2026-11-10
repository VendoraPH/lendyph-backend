<?php

namespace App\Services\Savings\Approvals;

use App\Models\SavingsApproval;
use App\Models\SavingsTransaction;
use App\Models\TimeDeposit;
use App\Models\User;
use App\Services\Savings\SavingsLocks;
use App\Services\Savings\SavingsMath;
use App\Services\Savings\TimeDeposits\Settlement;
use Illuminate\Validation\ValidationException;

/**
 * Carries out an approved time deposit payout at maturity (`td_payout`,
 * design §2): a settlement whose cash payout was above tier 1. The approval
 * desk holds the payout account's lock (when it pays into savings) and the
 * placement's; this re-checks the placement is still waiting to be settled
 * and that the payout is still the approved amount, then settles it as the
 * checker. Its own approval is no savings outflow, so no available-balance
 * check is involved.
 */
class TdPayoutExecutor implements ApprovalExecutor
{
    public function __construct(
        private readonly Settlement $settlement,
        private readonly SavingsLocks $locks,
    ) {}

    public function accountIds(SavingsApproval $approval): array
    {
        if (($approval->payload['payout_method'] ?? null) !== 'savings') {
            return [];
        }

        $accountId = TimeDeposit::query()->whereKey($approval->time_deposit_id)->value('payout_account_id');

        return $accountId === null ? [] : [(int) $accountId];
    }

    public function timeDepositIds(SavingsApproval $approval): array
    {
        return $approval->time_deposit_id === null ? [] : [(int) $approval->time_deposit_id];
    }

    public function execute(SavingsApproval $approval, User $checker): ?SavingsTransaction
    {
        // Locking reads in the design's order (accounts, then the placement),
        // whatever the desk already holds: two approvals of one placement
        // carried out at once settle it once (review M1).
        $this->locks->accounts($this->accountIds($approval));
        $deposit = $this->locks->timeDeposit((int) $approval->time_deposit_id);
        $payload = $approval->payload ?? [];

        if (! in_array($deposit->status, Settlement::OPEN_STATUSES, true)) {
            throw ValidationException::withMessages(['approval' => [
                "{$deposit->certificate_number} was already settled, so this payout can't be carried out. Reject it instead.",
            ]]);
        }

        $taxRate = isset($payload['post_maturity_tax_rate']) ? (string) $payload['post_maturity_tax_rate'] : null;
        $paidOut = $this->settlement->split($deposit, (string) $payload['action'], (string) $payload['date'], $taxRate)['paid_out'];

        if ($paidOut !== SavingsMath::centavos($approval->amount)) {
            throw ValidationException::withMessages(['approval' => [
                "{$deposit->certificate_number}'s payout is no longer the ₱".number_format(SavingsMath::centavos($approval->amount) / 100, 2).' approved. Reject this request and settle it again.',
            ]]);
        }

        return $this->settlement->settle(
            $deposit,
            (string) $payload['date'],
            (string) $payload['action'],
            $payload['payout_method'] ?? null,
            $checker->id,
            $payload['next_instruction'] ?? null,
            $taxRate,
        )['credit'];
    }
}
