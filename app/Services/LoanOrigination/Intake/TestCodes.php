<?php

namespace App\Services\LoanOrigination\Intake;

use App\Models\OriginationLink;
use App\Models\Role;
use App\Models\User;
use App\Support\PhilippineMobile;
use Illuminate\Database\Eloquent\Builder;

/**
 * The staging test-code mode (origination decision 7; finish run, round 4).
 * With `loan_origination_intake.otp.test_codes` on, a code asked for by a
 * staff number on an open link marked as a test (`is_test`; not turned off
 * or expired) is shown on screen ("Test mode: not sent") and never sent. A
 * staff number is one listed in `reminders.allow_list`, or the mobile number
 * on an active staff account of this deployment that works on loan
 * origination (STAFF_PERMISSION), so testers keep their own number on their
 * account and need no server setting.
 *
 * A staff number typed on a real or closed link gets nothing, and so does
 * any other number: `reminders.allow_all_recipients` never widens it, and a
 * staff account's number never widens who a code is sent to (ApplicantSms
 * sends only to the allow-list). Never on in production, which refuses to
 * boot with it on (LoanOriginationServiceProvider), and checked here again in
 * case the boot check is ever bypassed.
 */
final class TestCodes
{
    public const NOTICE = 'Test mode: not sent';

    public const PRODUCTION_REFUSAL = 'LOAN_ORIGINATION_OTP_TEST_CODES must be off in production: the test-code mode shows codes on screen instead of sending them.';

    /** What a staff account needs for its number to count: it opens Loan Origination. */
    public const STAFF_PERMISSION = 'loan_origination:view';

    public function enabled(): bool
    {
        return config('loan_origination_intake.otp.test_codes') === true && ! app()->environment('production');
    }

    /**
     * Whether test codes work on $link: only on a link marked as a test that
     * takes applications now (OriginationLink::status(), the rule the landing
     * page and the code step close a link by).
     */
    public function enabledOn(OriginationLink $link): bool
    {
        return $this->enabled() && $link->is_test && $link->status() === OriginationLink::STATUS_ACTIVE;
    }

    /** Whether $mobile gets its code on screen on $link. */
    public function serves(OriginationLink $link, string $mobile): bool
    {
        $wanted = PhilippineMobile::normalise($mobile);

        if (! $this->enabledOn($link) || $wanted === null) {
            return false;
        }

        return $this->allowListed($wanted) || $this->onOriginationStaffAccount($wanted);
    }

    private function allowListed(string $wanted): bool
    {
        foreach ((array) config('reminders.allow_list', []) as $entry) {
            if (! str_contains((string) $entry, '@') && PhilippineMobile::normalise((string) $entry) === $wanted) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $wanted is the mobile number on an active staff account (`status`
     * `active`, so any other status fails closed) that holds STAFF_PERMISSION
     * as the Gate reads it: super_admin by its role alone (Gate::before), or
     * the permission through a role that is switched on (admin and super_admin
     * always count, User::activeRoles()) or given directly. Both numbers go
     * through PhilippineMobile::normalise.
     *
     * Stored numbers are free text (Users takes spaces, dashes and +63), so no
     * index finds one by its normalised form. One query narrows the accounts to
     * those whose number ends in the same 10 digits (REGEXP_REPLACE, MySQL 8,
     * as MemberMatches matches mobiles): the database reads every active staff
     * account once, a cooperative's employees, and returns only the few that
     * share those digits. Each is then normalised here, so a number that only
     * ends the same (+1 917…) never counts.
     */
    private function onOriginationStaffAccount(string $wanted): bool
    {
        $grants = fn (Builder $permissions) => $permissions->where('permissions.name', self::STAFF_PERMISSION);

        return User::query()
            ->active()
            ->whereNotNull('mobile_number')
            ->whereRaw("RIGHT(REGEXP_REPLACE(mobile_number, '[^0-9]', ''), 10) = ?", [substr($wanted, -10)])
            ->where(fn (Builder $users) => $users
                ->whereHas('roles', fn (Builder $roles) => $roles->where('roles.name', 'super_admin'))
                ->orWhereHas('roles', fn (Builder $roles) => $roles
                    ->where(fn (Builder $active) => $active->where('roles.is_active', true)->orWhereIn('roles.name', Role::PROTECTED_KEYS))
                    ->whereHas('permissions', $grants))
                ->orWhereHas('permissions', $grants))
            ->pluck('mobile_number')
            ->contains(fn (string $stored): bool => PhilippineMobile::normalise($stored) === $wanted);
    }
}
