<?php

namespace App\Services\Savings\Approvals;

use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\Savings\Api\AccountCloser;
use App\Services\Savings\Api\Refuse;

/**
 * An approved close (slice S2): re-checks the blockers under the account
 * lock (this request itself doesn't count), then credits the accrued interest
 * and pays the balance out as AccountCloser does, closed by the maker.
 */
class CloseExecutor implements ApprovalExecutor
{
    public function __construct(private readonly AccountCloser $closer) {}

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

        if (empty($payload['date']) || empty($payload['payout_method'])) {
            Refuse::field('approval', 'This request is missing its date or payout method. Reject it and make a new one.');
        }

        return $this->closer->execute($account, [
            'date' => $payload['date'],
            'reason' => (string) ($payload['reason'] ?? 'Closed'),
            'payout_method' => $payload['payout_method'],
        ], $approval->requested_by, $checker->id, $approval);
    }
}
