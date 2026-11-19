<?php

namespace App\Services\LoanOrigination\Intake;

use App\Support\PhilippineMobile;

/**
 * The staging test-code mode (origination decision 7). With
 * `loan_origination_intake.otp.test_codes` on, a code asked for by a staff
 * number listed in `reminders.allow_list` is shown on screen ("Test mode: not
 * sent") and never sent. Only numbers listed there count:
 * `reminders.allow_all_recipients` never widens it. Never on in production,
 * which refuses to boot with it on (LoanOriginationServiceProvider), and
 * checked here again in case the boot check is ever bypassed.
 */
final class TestCodes
{
    public const NOTICE = 'Test mode: not sent';

    public const PRODUCTION_REFUSAL = 'LOAN_ORIGINATION_OTP_TEST_CODES must be off in production: the test-code mode shows codes on screen instead of sending them.';

    public function enabled(): bool
    {
        return config('loan_origination_intake.otp.test_codes') === true && ! app()->environment('production');
    }

    /** Whether $mobile gets its code on screen. */
    public function serves(string $mobile): bool
    {
        $wanted = PhilippineMobile::normalise($mobile);

        if (! $this->enabled() || $wanted === null) {
            return false;
        }

        foreach ((array) config('reminders.allow_list', []) as $entry) {
            if (! str_contains((string) $entry, '@') && PhilippineMobile::normalise((string) $entry) === $wanted) {
                return true;
            }
        }

        return false;
    }
}
