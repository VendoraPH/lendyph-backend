<?php

namespace App\Services\Savings\Approvals;

use App\Models\SavingsApproval;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\Savings\Api\Reverser;

/**
 * An approved reversal (slice S2): posts the linked reversal row (both legs
 * for a transfer) and reverses the original journal. See Reverser.
 */
class ReversalExecutor implements ApprovalExecutor
{
    public function __construct(private readonly Reverser $reverser) {}

    public function accountIds(SavingsApproval $approval): array
    {
        return $this->reverser->accountIds($approval);
    }

    public function timeDepositIds(SavingsApproval $approval): array
    {
        return [];
    }

    public function execute(SavingsApproval $approval, User $checker): ?SavingsTransaction
    {
        return $this->reverser->execute($approval, $checker);
    }
}
