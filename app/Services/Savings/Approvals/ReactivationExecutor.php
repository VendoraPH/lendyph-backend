<?php

namespace App\Services\Savings\Approvals;

use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\Savings\Api\StatusChanger;

/**
 * An approved reactivation (gap round S4): under the account lock the desk
 * took, re-checks that the account is still dormant, then makes it active
 * through StatusChanger::reactivate(). Moves no money, so it returns no row.
 *
 * When the account is no longer dormant (a deposit or transfer in reactivated
 * it meanwhile, or it was frozen), nothing changes on the account
 * and the approval ends with "No longer needed" and the reason in words.
 */
class ReactivationExecutor implements ApprovalExecutor
{
    public function __construct(private readonly StatusChanger $changer) {}

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

        if ($account->status !== SavingsAccount::STATUS_DORMANT) {
            $approval->update(['decision_reason' => self::noLongerNeeded($account)]);

            return null;
        }

        $this->changer->reactivate($account, (string) ($approval->payload['reason'] ?? 'Reactivated'), $approval->requester, $checker);

        return null;
    }

    /** Why a reactivation was no longer needed, in words. */
    public static function noLongerNeeded(SavingsAccount $account): string
    {
        $now = match ($account->status) {
            SavingsAccount::STATUS_ACTIVE => 'is already active (money paid in reactivated it)',
            SavingsAccount::STATUS_FROZEN => 'is frozen now; unfreeze it first',
            default => "is {$account->status} now",
        };

        return "No longer needed: {$account->account_number} {$now}. Nothing was changed.";
    }
}
