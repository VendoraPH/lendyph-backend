<?php

namespace App\Services\Savings\Notices;

use App\Models\AuditLog;
use App\Models\SavingsAccount;
use App\Notifications\StaffNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Notices for an account's status events (gap round S4, Savings TASK 47),
 * read from the audit row every path writes, so no path is missed: the
 * nightly dormancy job (`savings_account_dormant`), any reactivation (an
 * approved one, a deposit, a transfer in, a time deposit payout or an
 * auto-credit: `savings_account_reactivated`), a freeze and a closing.
 *
 * Each goes to the member through the portal (MemberNotice). Dormancy also
 * tells the `savings:view` holders who can see the branch, so someone follows
 * the member up. Written after the event's transaction commits; a failure is
 * logged and never undoes the event.
 */
class AccountEventNotices
{
    public const TYPE_DORMANT = 'savings_account_dormant';

    /** @var array<string, array{0: string, 1: string}> action => [member title, member body with %s for the account number] */
    private const MEMBER = [
        'savings_account_dormant' => ['Savings account dormant', 'Your savings account %s is now dormant because it had no deposit, withdrawal or transfer for a long time. It still earns interest. Make a deposit or visit your branch to make it active again.'],
        'savings_account_reactivated' => ['Savings account active again', 'Your savings account %s is active again.'],
        'savings_account_frozen' => ['Savings account frozen', 'Your savings account %s was frozen: no money can go in or out for now. Please contact your branch.'],
        'savings_account_closed' => ['Savings account closed', 'Your savings account %s was closed.'],
    ];

    public function __construct(
        private readonly StaffRecipients $staff,
        private readonly MemberNotice $member,
    ) {}

    public static function handles(AuditLog $row): bool
    {
        return isset(self::MEMBER[$row->action]) && $row->auditable_type === SavingsAccount::class && $row->auditable_id !== null;
    }

    public function after(AuditLog $row): void
    {
        $action = $row->action;
        $accountId = (int) $row->auditable_id;
        $actorId = $row->user_id === null ? null : (int) $row->user_id;

        try {
            DB::afterCommit(function () use ($action, $accountId, $actorId): void {
                try {
                    $this->send($action, $accountId, $actorId);
                } catch (Throwable $e) {
                    $this->logFailure($action, $accountId, $e);
                }
            });
        } catch (Throwable $e) {
            $this->logFailure($action, $accountId, $e);
        }
    }

    private function send(string $action, int $accountId, ?int $actorId): void
    {
        $account = SavingsAccount::query()->with('borrower')->find($accountId);

        if ($account === null) {
            return;
        }

        [$title, $body] = self::MEMBER[$action];
        $this->member->send($account->borrower_id, 'savings', $title, sprintf($body, $account->account_number), "/member/savings/{$account->id}");

        if ($action !== 'savings_account_dormant') {
            return;
        }

        $recipients = $this->staff->holding('savings:view', $account->branch_id, array_values(array_filter([$actorId])));

        if ($recipients->isEmpty()) {
            return;
        }

        $member = $account->borrower?->full_name ?? 'a member';
        $since = $account->dormant_since?->format('M j, Y') ?? now()->format('M j, Y');

        Notification::send($recipients, new StaffNotification(
            self::TYPE_DORMANT,
            'Savings account dormant',
            "Savings account {$account->account_number} ({$member}) became dormant on {$since} after no member activity. A deposit or an approved reactivation makes it active again.",
            "/savings/accounts/{$account->id}",
            ['account_id' => $account->id],
        ));
    }

    private function logFailure(string $action, int $accountId, Throwable $e): void
    {
        Log::error('A savings account notice could not be sent; the account change was kept.', [
            'action' => $action,
            'savings_account_id' => $accountId,
            'exception' => $e,
        ]);
    }
}
