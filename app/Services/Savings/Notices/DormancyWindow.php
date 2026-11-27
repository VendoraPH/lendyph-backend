<?php

namespace App\Services\Savings\Notices;

use App\Models\SavingsAccount;
use App\Models\SavingsSetting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * When an account turns dormant, by the nightly job's own rule
 * (SavingsMarkDormant): on day D an active account turns dormant once its
 * last member activity (the opening date when there has been none) is on or
 * before D less `dormancy_months` (calendar months, no overflow).
 *
 * An account is **approaching** dormancy when it would turn dormant within
 * `dormancy_warning_days` from today, but not tonight: its last activity is
 * after today's cut-off and on or before the cut-off of the day
 * `dormancy_warning_days` ahead.
 */
final class DormancyWindow
{
    private const LAST_ACTIVITY = 'COALESCE(savings_accounts.last_member_activity_on, savings_accounts.opened_on)';

    public function __construct(
        public readonly CarbonImmutable $today,
        public readonly int $months,
        public readonly int $warningDays,
    ) {}

    public static function current(?SavingsSetting $settings = null): self
    {
        $settings ??= SavingsSetting::current();

        return new self(
            CarbonImmutable::parse(now()->toDateString()),
            max(1, (int) $settings->dormancy_months),
            max(0, (int) ($settings->dormancy_warning_days ?? SavingsSetting::DEFAULTS['dormancy_warning_days'])),
        );
    }

    /** The last activity date on or before which an account is dormant on `$day`. */
    public function cutoffOn(CarbonImmutable $day): string
    {
        return $day->subMonthsNoOverflow($this->months)->toDateString();
    }

    /**
     * Active accounts turning dormant within the warning window, soonest first.
     *
     * @param  Builder<SavingsAccount>  $query
     * @return Builder<SavingsAccount>
     */
    public function approaching(Builder $query): Builder
    {
        return $query
            ->where('savings_accounts.status', SavingsAccount::STATUS_ACTIVE)
            ->whereRaw(self::LAST_ACTIVITY.' > ?', [$this->cutoffOn($this->today)])
            ->whereRaw(self::LAST_ACTIVITY.' <= ?', [$this->cutoffOn($this->today->addDays($this->warningDays))])
            ->orderByRaw(self::LAST_ACTIVITY)
            ->orderBy('savings_accounts.id');
    }

    /** The first day the account turns dormant, with no further member activity. */
    public function dormantOn(SavingsAccount $account): CarbonImmutable
    {
        $last = CarbonImmutable::parse(($account->last_member_activity_on ?? $account->opened_on)->toDateString());
        $day = $last->addMonthsNoOverflow($this->months);

        while ($this->cutoffOn($day) < $last->toDateString()) {
            $day = $day->addDay();
        }

        return $day;
    }

    public function daysLeft(SavingsAccount $account): int
    {
        return (int) $this->today->diffInDays($this->dormantOn($account));
    }
}
