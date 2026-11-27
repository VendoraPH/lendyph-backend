<?php

namespace App\Services\Savings\Notices;

use App\Models\SavingsAccount;
use App\Models\SavingsSetting;
use App\Models\TimeDeposit;
use App\Notifications\StaffNotification;
use App\Services\Portal\Observers\MoneyNotices;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The nightly savings notices (`savings:notices`, after the 00:40 maturity
 * job), in-app only: staff notifications and, where the portal is on, the
 * member's portal notices. Never SMS or email.
 *
 * - **Time deposit maturing**, at each of `notice_days` before maturity (0 is
 *   the day itself): to `savings:view` holders who can see the deposit's
 *   branch, and to the member. On a night it sends the latest day-mark the
 *   deposit has reached and not yet been told about, so a missed night
 *   catches up with one notice, never a backlog. A placement made inside a
 *   window is told on its first night.
 * - **Dormancy warning**, once when an account enters the approaching window
 *   (DormancyWindow, `dormancy_warning_days`): to `savings:view` holders in
 *   the branch, and to the member. Member activity moves the dormancy date,
 *   so a later window warns again.
 *
 * Each notice claims its key in NoticeLog in the same transaction that
 * writes it, so a re-run sends nothing new. One failure is logged and the
 * run carries on.
 */
class NightlyNotices
{
    public const TYPE_MATURING = 'savings_time_deposit_maturing';

    public const TYPE_DORMANCY_WARNING = 'savings_dormancy_warning';

    private const CHUNK = 200;

    public function __construct(
        private readonly NoticeLog $log,
        private readonly StaffRecipients $staff,
        private readonly MemberNotice $member,
    ) {}

    /** @return array{maturing: int, dormancy_warnings: int} */
    public function run(): array
    {
        return ['maturing' => $this->maturing(), 'dormancy_warnings' => $this->dormancyWarnings()];
    }

    public function maturing(): int
    {
        $marks = SavingsSetting::current()->noticeDays();

        if ($marks === []) {
            return 0;
        }

        $today = CarbonImmutable::parse(now()->toDateString());
        $sent = 0;

        TimeDeposit::query()
            ->where(fn ($q) => $q
                ->where(fn ($ahead) => $ahead->where('status', 'active')
                    ->where('maturity_date', '>', $today->toDateString())
                    ->where('maturity_date', '<=', $today->addDays(max($marks))->toDateString()))
                ->when(in_array(0, $marks, true), fn ($q) => $q->orWhere(fn ($day) => $day
                    ->where('maturity_date', $today->toDateString())
                    ->where('status', '!=', 'preterminated'))))
            ->with('borrower')
            ->chunkById(self::CHUNK, function (Collection $deposits) use ($marks, $today, &$sent): void {
                foreach ($deposits as $deposit) {
                    $daysLeft = (int) $today->diffInDays(CarbonImmutable::parse($deposit->maturity_date->toDateString()));
                    $mark = $this->markFor($marks, $daysLeft);

                    if ($mark !== null && $this->maturingNotice($deposit, $mark, $daysLeft)) {
                        $sent++;
                    }
                }
            });

        return $sent;
    }

    /**
     * The day-mark a deposit with `$daysLeft` has reached: the smallest mark
     * at or above it (0 only on the day).
     *
     * @param  list<int>  $marks
     */
    public function markFor(array $marks, int $daysLeft): ?int
    {
        $reached = array_filter($marks, fn (int $mark) => $mark >= $daysLeft && ($mark > 0 || $daysLeft === 0));

        return $reached === [] ? null : min($reached);
    }

    public function dormancyWarnings(): int
    {
        $window = DormancyWindow::current();
        $sent = 0;

        $window->approaching(SavingsAccount::query())
            ->reorder()
            ->with('borrower')
            ->chunkById(self::CHUNK, function (Collection $accounts) use ($window, &$sent): void {
                foreach ($accounts as $account) {
                    if ($this->dormancyWarning($account, $window)) {
                        $sent++;
                    }
                }
            });

        return $sent;
    }

    private function maturingNotice(TimeDeposit $deposit, int $mark, int $daysLeft): bool
    {
        $date = CarbonImmutable::parse($deposit->maturity_date->toDateString())->format('M j, Y');
        $amount = MoneyNotices::peso($deposit->maturity_amount);
        $when = $daysLeft === 0 ? 'today' : "on {$date}, in {$daysLeft} ".($daysLeft === 1 ? 'day' : 'days');
        $member = $deposit->borrower?->full_name ?? 'a member';

        return $this->once("td_maturing:{$deposit->id}:{$deposit->maturity_date->toDateString()}:{$mark}", self::TYPE_MATURING, null, $deposit->id, function () use ($deposit, $when, $amount, $member, $daysLeft, $mark): void {
            $this->toStaff($deposit->branch_id, new StaffNotification(
                self::TYPE_MATURING,
                $daysLeft === 0 ? 'Time deposit matures today' : 'Time deposit maturing',
                "Time deposit {$deposit->certificate_number} ({$member}) matures {$when}. Maturity amount: {$amount}.",
                "/savings/time-deposits/{$deposit->id}",
                ['time_deposit_id' => $deposit->id, 'maturity_date' => $deposit->maturity_date->toDateString(), 'days_before' => $mark],
            ));
            $this->member->send(
                $deposit->borrower_id,
                'time_deposits',
                $daysLeft === 0 ? 'Time deposit matures today' : 'Time deposit maturing',
                "Your time deposit {$deposit->certificate_number} matures {$when}. Maturity amount: {$amount}.",
                "/member/time-deposits/{$deposit->id}",
            );
        });
    }

    private function dormancyWarning(SavingsAccount $account, DormancyWindow $window): bool
    {
        $on = $window->dormantOn($account);
        $daysLeft = $window->daysLeft($account);
        $date = $on->format('M j, Y');
        $in = $daysLeft === 1 ? 'in 1 day' : "in {$daysLeft} days";
        $member = $account->borrower?->full_name ?? 'a member';

        return $this->once("dormancy_warning:{$account->id}:{$on->toDateString()}", self::TYPE_DORMANCY_WARNING, $account->id, null, function () use ($account, $on, $date, $in, $member): void {
            $this->toStaff($account->branch_id, new StaffNotification(
                self::TYPE_DORMANCY_WARNING,
                'Savings account dormant soon',
                "Savings account {$account->account_number} ({$member}) turns dormant on {$date}, {$in}, unless the member makes a deposit, withdrawal or transfer before then.",
                "/savings/accounts/{$account->id}",
                ['account_id' => $account->id, 'dormant_on' => $on->toDateString()],
            ));
            $this->member->send(
                $account->borrower_id,
                'savings',
                'Savings account inactive soon',
                "Your savings account {$account->account_number} becomes dormant on {$date} if there is no deposit, withdrawal or transfer before then. Make one, or visit your branch, to keep it active.",
                "/member/savings/{$account->id}",
            );
        });
    }

    /** Claim the key and write the notice in one transaction; false when it was sent before or failed. */
    private function once(string $key, string $type, ?int $accountId, ?int $timeDepositId, callable $send): bool
    {
        try {
            return DB::transaction(function () use ($key, $type, $accountId, $timeDepositId, $send): bool {
                if (! $this->log->claim($key, $type, $accountId, $timeDepositId)) {
                    return false;
                }

                $send();

                return true;
            });
        } catch (Throwable $e) {
            Log::error('A nightly savings notice could not be sent; the next run tries again.', ['notice_key' => $key, 'exception' => $e]);

            return false;
        }
    }

    private function toStaff(?int $branchId, StaffNotification $notice): void
    {
        $recipients = $this->staff->holding('savings:view', $branchId);

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, $notice);
        }
    }
}
