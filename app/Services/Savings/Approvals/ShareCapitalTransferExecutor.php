<?php

namespace App\Services\Savings\Approvals;

use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\Savings\Api\Refuse;
use App\Services\Savings\Api\Teller;
use App\Services\Savings\SavingsMath;
use App\Services\Savings\ShareCapitalTransfer;

/**
 * An approved transfer from savings to share capital (gap round S3): checks
 * again, under the account lock, that the member is still an active member
 * in the account's branch, that the account can still pay out within its
 * product's rules, and that the money is still there (leaving out the amount
 * this request reserves itself), then posts it as the maker's transfer
 * (ShareCapitalTransfer::post()), linked to the approval, with the checker
 * as the journal's poster.
 */
class ShareCapitalTransferExecutor implements ApprovalExecutor
{
    public function __construct(
        private readonly ShareCapitalTransfer $transfer,
        private readonly Teller $teller,
    ) {}

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

        if (empty($payload['date'])) {
            Refuse::field('approval', 'This request is missing its date. Reject it and make a new one.');
        }

        $this->transfer->assertCanTransfer($account, $centavos);
        $this->teller->assertAvailable($account, $centavos, $centavos);

        return $this->transfer->post($account, $centavos, [
            'date' => (string) $payload['date'],
            'remarks' => $payload['remarks'] ?? null,
        ], $approval->requested_by, $approval->id, $checker->id, [$approval->first_checker_id])['transaction'];
    }
}
