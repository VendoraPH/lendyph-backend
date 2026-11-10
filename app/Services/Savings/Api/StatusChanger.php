<?php

namespace App\Services\Savings\Api;

use App\Models\SavingsAccount;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Savings\SavingsLocks;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Freeze, unfreeze or reactivate an account by hand (design §2,
 * `POST …/{id}/status`), each with a reason and an audit row.
 *
 * - **Freeze** an active or dormant account: nothing moves in or out until
 *   it is unfrozen (holds can still be placed).
 * - **Unfreeze** a frozen account: it becomes active.
 * - **Reactivate** a dormant account. Staff reactivate one when the member
 *   comes in, so it counts as member activity: `last_member_activity_on`
 *   becomes today, or the nightly dormancy job would mark it dormant again.
 *
 * A closed account keeps its status.
 */
class StatusChanger
{
    public function __construct(private readonly SavingsLocks $locks) {}

    /** @throws ValidationException */
    public function change(int $accountId, string $status, string $reason, User $user): SavingsAccount
    {
        $account = $this->locks->account($accountId);
        $from = $account->status;

        if ($account->isClosed()) {
            Refuse::field('status', "{$account->account_number} is closed; its status can't change.");
        }

        if ($from === $status) {
            Refuse::field('status', "{$account->account_number} is already {$status}.");
        }

        $today = now()->toDateString();
        [$action, $changes, $words] = match (true) {
            $status === SavingsAccount::STATUS_FROZEN => ['savings_account_frozen', ['status' => $status], 'frozen'],
            $from === SavingsAccount::STATUS_FROZEN => ['savings_account_unfrozen', ['status' => $status, 'dormant_since' => null], 'unfrozen'],
            default => ['savings_account_reactivated', ['status' => $status, 'dormant_since' => null, 'last_member_activity_on' => $today], 'reactivated'],
        };

        $before = [
            'status' => $from,
            'dormant_since' => $account->dormant_since?->toDateString(),
            'last_member_activity_on' => $account->last_member_activity_on?->toDateString(),
        ];

        DB::table('savings_accounts')->where('id', $account->id)->update([...$changes, 'updated_at' => now()]);
        $account->refresh();

        AuditLogService::log(
            $action,
            $account,
            array_intersect_key($before, $changes),
            array_intersect_key([
                'status' => $account->status,
                'dormant_since' => $account->dormant_since?->toDateString(),
                'last_member_activity_on' => $account->last_member_activity_on?->toDateString(),
            ], $changes),
            "Savings account {$account->account_number} {$words} by {$user->full_name}: {$reason}",
        );

        return $account;
    }
}
