<?php

namespace App\Services\Savings\Notices;

use App\Models\SavingsApproval;
use App\Notifications\StaffNotification;
use App\Services\Savings\Api\Refuse;
use App\Services\Savings\SavingsMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * "Approval waiting" (gap round S4): when a savings approval opens, every
 * active `savings:approve` holder who can see its branch (StaffRecipients, as
 * the large-transaction alert) is told in the app, never the maker. When a
 * two-checker request gets its first approval, the same holders except the
 * maker and the first checker are told it waits for a second checker
 * (`data.checkers_left` 1). `data.stage` says which: `first` when it opens,
 * `second` when one checker is left (gap round B7). Written after the write commits; a failure is
 * logged and never fails it.
 */
class ApprovalWaitingNotice
{
    public const TYPE = 'savings_approval_waiting';

    /** `data.stage`: the approval has just opened (gap round B7). */
    public const STAGE_FIRST = 'first';

    /** `data.stage`: one checker has approved and one is left (gap round B7). */
    public const STAGE_SECOND = 'second';

    /** Each kind in words, for the notice. */
    private const KIND_WORDS = [
        'withdrawal' => 'A withdrawal',
        'close' => 'An account closing',
        'td_payout' => 'A time deposit payout',
        'pretermination' => 'A time deposit early termination',
        'reversal' => 'A reversal',
        'share_capital_transfer' => 'A transfer to share capital',
        'reactivation' => 'A reactivation',
    ];

    public function __construct(private readonly StaffRecipients $staff) {}

    public function after(SavingsApproval $approval): void
    {
        $approvalId = (int) $approval->getKey();

        try {
            DB::afterCommit(function () use ($approvalId): void {
                try {
                    $this->send($approvalId);
                } catch (Throwable $e) {
                    $this->logFailure($approvalId, $e);
                }
            });
        } catch (Throwable $e) {
            $this->logFailure($approvalId, $e);
        }
    }

    private function send(int $approvalId): void
    {
        $approval = SavingsApproval::query()
            ->with(['account.borrower', 'timeDeposit.borrower', 'requester', 'firstChecker'])
            ->find($approvalId);

        if ($approval === null || $approval->status !== 'pending') {
            return;
        }

        $holder = $approval->account ?? $approval->timeDeposit;
        $second = $approval->first_checker_id !== null;
        $skip = array_values(array_filter([(int) $approval->requested_by, $second ? (int) $approval->first_checker_id : null]));
        $recipients = $this->staff->holding('savings:approve', $holder?->branch_id, $skip);

        if ($recipients->isEmpty()) {
            return;
        }

        $what = self::KIND_WORDS[$approval->kind] ?? ucfirst(str_replace('_', ' ', $approval->kind));
        $on = $approval->account?->account_number ?? $approval->timeDeposit?->certificate_number;
        $member = $holder?->borrower?->full_name;
        $amount = SavingsMath::centavos($approval->amount);
        $by = $approval->requester?->full_name ?? 'staff';

        $body = $what
            .($on !== null ? " on {$on}" : '')
            .($member !== null ? " ({$member})" : '')
            .($amount > 0 ? ' for '.Refuse::peso($amount) : '')
            .($second
                ? ' has its first approval from '.($approval->firstChecker?->full_name ?? 'a checker').' and is waiting for a second checker'
                : ' is waiting for approval')
            .", requested by {$by}.";

        Notification::send($recipients, new StaffNotification(
            self::TYPE,
            $second ? 'Second savings approval waiting' : 'Savings approval waiting',
            $body,
            '/savings/approvals',
            [
                'approval_id' => $approval->id,
                'kind' => $approval->kind,
                'account_id' => $approval->account_id,
                'time_deposit_id' => $approval->time_deposit_id,
                'checkers_left' => $second ? 1 : $approval->required_checkers,
                'stage' => $second ? self::STAGE_SECOND : self::STAGE_FIRST,
            ],
        ));
    }

    private function logFailure(int $approvalId, Throwable $e): void
    {
        Log::error('The savings approval-waiting notice could not be sent; the request was kept.', [
            'savings_approval_id' => $approvalId,
            'exception' => $e,
        ]);
    }
}
