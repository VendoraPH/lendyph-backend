<?php

namespace App\Services\LoanOrigination\Intake;

use App\Models\OriginationLink;
use App\Models\User;
use App\Support\PhilippineMobile;

/**
 * The staging test-code mode (origination decision 7; finish run, round 4).
 * With `loan_origination_intake.otp.test_codes` on, a code asked for by a
 * staff number on a link marked as a test (`is_test`) is shown on screen
 * ("Test mode: not sent") and never sent. A staff number is one listed in
 * `reminders.allow_list`, or the mobile number on an active staff account of
 * this deployment (`users.mobile_number`), so testers keep their own number on
 * their account and need no server setting.
 *
 * A staff number typed on a real link gets nothing, and so does any other
 * number: `reminders.allow_all_recipients` never widens it, and a staff
 * account's number never widens who a code is sent to (ApplicantSms sends
 * only to the allow-list). Never on in production, which refuses to boot with
 * it on (LoanOriginationServiceProvider), and checked here again in case the
 * boot check is ever bypassed.
 */
final class TestCodes
{
    public const NOTICE = 'Test mode: not sent';

    public const PRODUCTION_REFUSAL = 'LOAN_ORIGINATION_OTP_TEST_CODES must be off in production: the test-code mode shows codes on screen instead of sending them.';

    public function enabled(): bool
    {
        return config('loan_origination_intake.otp.test_codes') === true && ! app()->environment('production');
    }

    /** Whether test codes work on $link: only on a link marked as a test. */
    public function enabledOn(OriginationLink $link): bool
    {
        return $this->enabled() && $link->is_test;
    }

    /** Whether $mobile gets its code on screen on $link. */
    public function serves(OriginationLink $link, string $mobile): bool
    {
        $wanted = PhilippineMobile::normalise($mobile);

        if (! $this->enabledOn($link) || $wanted === null) {
            return false;
        }

        return $this->allowListed($wanted) || $this->onActiveStaffAccount($wanted);
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
     * Whether $wanted is the mobile number on an active staff account, both
     * sides through PhilippineMobile::normalise. Active is what
     * EnsureUserIsActive lets in: any status but `inactive`.
     *
     * Stored numbers are free text (Users takes spaces, dashes and +63), so no
     * index finds one by its normalised form. One query narrows the accounts to
     * those whose number ends in the same 10 digits (REGEXP_REPLACE, MySQL 8,
     * as MemberMatches matches mobiles): the database reads every active staff
     * account once, a cooperative's employees, and returns only the few that
     * share those digits. Each is then normalised here, so a number that only
     * ends the same (+1 917…) never counts.
     */
    private function onActiveStaffAccount(string $wanted): bool
    {
        return User::query()
            ->where('status', '!=', 'inactive')
            ->whereNotNull('mobile_number')
            ->whereRaw("RIGHT(REGEXP_REPLACE(mobile_number, '[^0-9]', ''), 10) = ?", [substr($wanted, -10)])
            ->pluck('mobile_number')
            ->contains(fn (string $stored): bool => PhilippineMobile::normalise($stored) === $wanted);
    }
}
