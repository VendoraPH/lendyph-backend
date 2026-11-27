<?php

namespace App\Services\Savings\Api;

use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Savings\ApprovalGate;
use App\Services\Savings\Notices\DormancyWindow;
use App\Services\Savings\SavingsLocks;
use App\Support\Sentence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Freeze, unfreeze or reactivate an account by hand (design §2,
 * `POST …/{id}/status`), each with a reason and an audit row.
 *
 * - **Freeze** an active or dormant account: nothing moves in or out until
 *   it is unfrozen (holds can still be placed).
 * - **Unfreeze** a frozen account: it goes back to dormant when it was
 *   dormant before it was frozen (`dormant_since` is kept). One whose last
 *   member activity is past the dormancy cut-off (it would have turned
 *   dormant while frozen) becomes dormant from today; else it is active.
 * - **Reactivate** a dormant account (gap round S4, Savings TASK 20:
 *   "Reactivation requires approval"). Staff ask for it and a `reactivation`
 *   approval opens, with one checker who isn't the maker (ApprovalGate at
 *   ₱0.00). Once approved, ReactivationExecutor makes it active under the
 *   account lock ({@see self::reactivate()}). The member comes in to do it,
 *   so it counts as member activity: `last_member_activity_on` becomes the
 *   day it is approved, or the nightly dormancy job would mark it dormant
 *   again. A member's own deposit, a transfer in or a time deposit payout
 *   still reactivates an account by itself (SavingsLedger).
 *
 * A closed account keeps its status.
 */
class StatusChanger
{
    public function __construct(
        private readonly SavingsLocks $locks,
        private readonly ApprovalGate $gate,
    ) {}

    /**
     * Freeze or unfreeze at once; a reactivation opens its approval instead.
     *
     * @return array{account: SavingsAccount|null, approval: SavingsApproval|null}
     *
     * @throws ValidationException
     */
    public function change(int $accountId, string $status, string $reason, User $user): array
    {
        $account = $this->locks->account($accountId);
        $from = $account->status;

        if ($account->isClosed()) {
            Refuse::field('status', "{$account->account_number} is closed; its status can't change.");
        }

        if ($from === $status) {
            Refuse::field('status', "{$account->account_number} is already {$status}.");
        }

        if ($from === SavingsAccount::STATUS_DORMANT && $status === SavingsAccount::STATUS_ACTIVE) {
            return ['account' => null, 'approval' => $this->gate->open('reactivation', 0, [
                'account_id' => $account->id,
                'payload' => ['reason' => $reason],
                'live_key' => "reactivation:{$account->id}",
            ], $user->id)];
        }

        $wasDormant = $account->dormant_since !== null;
        $lastActivity = ($account->last_member_activity_on ?? $account->opened_on)?->toDateString();
        $window = DormancyWindow::current();
        $pastCutoff = $lastActivity !== null && $lastActivity <= $window->cutoffOn($window->today);
        [$action, $changes, $words] = match (true) {
            $status === SavingsAccount::STATUS_FROZEN => ['savings_account_frozen', ['status' => $status], 'frozen'],
            $wasDormant => ['savings_account_unfrozen', ['status' => SavingsAccount::STATUS_DORMANT], 'unfrozen (dormant again)'],
            $pastCutoff => ['savings_account_unfrozen', ['status' => SavingsAccount::STATUS_DORMANT, 'dormant_since' => $window->today->toDateString()],
                'unfrozen (dormant: no member activity since '.CarbonImmutable::parse($lastActivity)->format('M j, Y').')'],
            default => ['savings_account_unfrozen', ['status' => SavingsAccount::STATUS_ACTIVE], 'unfrozen'],
        };

        $this->write($account, $action, $changes, "Savings account {$account->account_number} {$words} by {$user->full_name}: {$reason}");

        return ['account' => $account, 'approval' => null];
    }

    /**
     * Make a dormant account active, as an approved reactivation (the caller
     * holds the account lock and has checked it is still dormant).
     */
    public function reactivate(SavingsAccount $account, string $reason, ?User $maker, User $checker): SavingsAccount
    {
        $by = $maker?->full_name ?? 'staff';

        return $this->write($account, 'savings_account_reactivated', [
            'status' => SavingsAccount::STATUS_ACTIVE,
            'dormant_since' => null,
            'last_member_activity_on' => now()->toDateString(),
        ], "Savings account {$account->account_number} reactivated at the request of {$by}, approved by {$checker->full_name}: ".Sentence::ended($reason));
    }

    /** @param  array<string, string|null>  $changes */
    private function write(SavingsAccount $account, string $action, array $changes, string $description): SavingsAccount
    {
        $before = $this->auditable($account);

        DB::table('savings_accounts')->where('id', $account->id)->update([...$changes, 'updated_at' => now()]);
        $account->refresh();

        AuditLogService::log(
            $action,
            $account,
            array_intersect_key($before, $changes),
            array_intersect_key($this->auditable($account), $changes),
            $description,
        );

        return $account;
    }

    /** @return array{status: string, dormant_since: string|null, last_member_activity_on: string|null} */
    private function auditable(SavingsAccount $account): array
    {
        return [
            'status' => $account->status,
            'dormant_since' => $account->dormant_since?->toDateString(),
            'last_member_activity_on' => $account->last_member_activity_on?->toDateString(),
        ];
    }
}
