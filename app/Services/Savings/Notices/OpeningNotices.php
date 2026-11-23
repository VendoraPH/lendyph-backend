<?php

namespace App\Services\Savings\Notices;

use App\Models\SavingsAccount;
use App\Models\TimeDeposit;
use App\Notifications\StaffNotification;
use App\Services\Portal\Observers\MoneyNotices;
use App\Services\Savings\Api\Refuse;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * "Account opened" and "time deposit placed" (Savings TASK 47), in-app only,
 * through the channels of the other savings notices: the member in the
 * portal (MemberNotice) and the `savings:view` holders who can see the
 * branch, never the person who did it (StaffRecipients).
 *
 * - An account opened by staff or on a member's approved portal request
 *   (AccountOpener).
 * - A time deposit placed by staff, or placed again by a rollover when one is
 *   settled, by staff or the nightly maturity job (Placement).
 *
 * Sent after the transaction commits, so an opening that is refused or rolled
 * back tells nobody; a failure is logged and never undoes the opening.
 */
class OpeningNotices
{
    public const TYPE_ACCOUNT_OPENED = 'savings_account_opened';

    public const TYPE_TIME_DEPOSIT_PLACED = 'savings_time_deposit_placed';

    public function __construct(
        private readonly StaffRecipients $staff,
        private readonly MemberNotice $member,
    ) {}

    /** @param  int  $openingDeposit  in centavos */
    public function accountOpened(SavingsAccount $account, int $openingDeposit, ?int $actorId): void
    {
        $accountId = (int) $account->getKey();

        $this->afterCommit(self::TYPE_ACCOUNT_OPENED, $accountId, fn () => $this->sendAccountOpened($accountId, $openingDeposit, $actorId));
    }

    public function timeDepositPlaced(TimeDeposit $deposit, ?int $actorId): void
    {
        $depositId = (int) $deposit->getKey();

        $this->afterCommit(self::TYPE_TIME_DEPOSIT_PLACED, $depositId, fn () => $this->sendTimeDepositPlaced($depositId, $actorId));
    }

    private function sendAccountOpened(int $accountId, int $openingDeposit, ?int $actorId): void
    {
        $account = SavingsAccount::query()->with(['borrower', 'product'])->find($accountId);

        if ($account === null) {
            return;
        }

        $date = $account->opened_on->format('M j, Y');
        $name = ($account->product?->name ?? 'Savings').' account '.$account->account_number;
        $deposit = $openingDeposit > 0 ? 'an opening deposit of '.Refuse::peso($openingDeposit) : 'no opening deposit';
        $member = $account->borrower?->full_name ?? 'a member';

        $this->member->send($account->borrower_id, 'savings', 'Savings account opened', "Your {$name} was opened on {$date}.", "/member/savings/{$account->id}");

        $this->toStaff($account->branch_id, $actorId, new StaffNotification(
            self::TYPE_ACCOUNT_OPENED,
            'Savings account opened',
            "{$name} ({$member}) was opened on {$date}, with {$deposit}.",
            "/savings/accounts/{$account->id}",
            ['account_id' => $account->id],
        ));
    }

    private function sendTimeDepositPlaced(int $depositId, ?int $actorId): void
    {
        $deposit = TimeDeposit::query()->with(['borrower', 'rolledOverFrom'])->find($depositId);

        if ($deposit === null) {
            return;
        }

        $placed = $deposit->placement_date->format('M j, Y');
        $matures = $deposit->maturity_date->format('M j, Y');
        $principal = MoneyNotices::peso($deposit->principal);
        $term = $deposit->term_days === 1 ? '1 day' : "{$deposit->term_days} days";
        $maturity = 'Maturity amount: '.MoneyNotices::peso($deposit->maturity_amount).'.';
        $member = $deposit->borrower?->full_name ?? 'a member';
        $from = $deposit->rolledOverFrom?->certificate_number;
        $certificate = $deposit->certificate_number;

        [$title, $toMember, $toStaff] = $from === null
            ? ['Time deposit placed',
                "Your time deposit {$certificate} of {$principal} was placed on {$placed} for {$term}. It matures on {$matures}. {$maturity}",
                "Time deposit {$certificate} ({$member}) was placed on {$placed}: {$principal} for {$term}, maturing on {$matures}. {$maturity}"]
            : ['Time deposit rolled over',
                "Your time deposit {$from} was rolled over into {$certificate}: {$principal} for {$term} from {$placed}. It matures on {$matures}. {$maturity}",
                "Time deposit {$from} ({$member}) was rolled over into {$certificate}: {$principal} for {$term} from {$placed}, maturing on {$matures}. {$maturity}"];

        $this->member->send($deposit->borrower_id, 'time_deposits', $title, $toMember, "/member/time-deposits/{$deposit->id}");

        $this->toStaff($deposit->branch_id, $actorId, new StaffNotification(
            self::TYPE_TIME_DEPOSIT_PLACED,
            $title,
            $toStaff,
            "/savings/time-deposits/{$deposit->id}",
            ['time_deposit_id' => $deposit->id, 'rolled_over_from_id' => $deposit->rolled_over_from_id],
        ));
    }

    private function toStaff(?int $branchId, ?int $actorId, StaffNotification $notice): void
    {
        $recipients = $this->staff->holding('savings:view', $branchId, array_values(array_filter([$actorId])));

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, $notice);
        }
    }

    private function afterCommit(string $type, int $id, Closure $send): void
    {
        try {
            DB::afterCommit(function () use ($type, $id, $send): void {
                try {
                    $send();
                } catch (Throwable $e) {
                    $this->logFailure($type, $id, $e);
                }
            });
        } catch (Throwable $e) {
            $this->logFailure($type, $id, $e);
        }
    }

    private function logFailure(string $type, int $id, Throwable $e): void
    {
        Log::error('A savings opening notice could not be sent; the opening was kept.', [
            'type' => $type,
            'id' => $id,
            'exception' => $e,
        ]);
    }
}
