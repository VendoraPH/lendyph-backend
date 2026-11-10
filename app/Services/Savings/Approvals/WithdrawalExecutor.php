<?php

namespace App\Services\Savings\Approvals;

use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\Savings\Api\Refuse;
use App\Services\Savings\Api\Teller;
use App\Services\Savings\SavingsMath;

/**
 * An approved withdrawal (slice S2): re-checks, under the account lock, that
 * the account is still active and the money is still there (leaving out the
 * amount this request itself reserves), then posts it as the maker's
 * withdrawal with its journal, linked to the approval.
 */
class WithdrawalExecutor implements ApprovalExecutor
{
    public function __construct(private readonly Teller $teller) {}

    public function accountIds(SavingsApproval $approval): array
    {
        return array_values(array_filter([$approval->account_id]));
    }

    public function timeDepositIds(SavingsApproval $approval): array
    {
        return [];
    }

    public function execute(SavingsApproval $approval, User $checker): ?SavingsTransaction
    {
        $account = SavingsAccount::query()->findOrFail($approval->account_id);
        $payload = $approval->payload ?? [];
        $centavos = SavingsMath::centavos($approval->amount);

        if (empty($payload['date']) || empty($payload['payment_method'])) {
            Refuse::field('approval', 'This request is missing its date or payment method. Reject it and make a new one.');
        }

        $this->teller->assertCanPayOut($account);
        $this->teller->assertAvailable($account, $centavos, $centavos);

        return $this->teller->postWithdrawal($account, $centavos, $payload, $approval->requested_by, $approval->id, $checker->id, [$approval->first_checker_id]);
    }
}
