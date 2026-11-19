<?php

namespace App\Services\LoanOrigination\Intake;

use App\Models\OriginationLink;
use App\Support\PhilippineMobile;

/**
 * The staging test-code mode (origination decision 7). With
 * `loan_origination_intake.otp.test_codes` on, a code asked for by a staff
 * number listed in `reminders.allow_list` is shown on screen ("Test mode: not
 * sent") and never sent, and only on a link marked as a test (`is_test`),
 * so a staff number typed on a real link gets nothing. Only numbers listed there count:
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

        foreach ((array) config('reminders.allow_list', []) as $entry) {
            if (! str_contains((string) $entry, '@') && PhilippineMobile::normalise((string) $entry) === $wanted) {
                return true;
            }
        }

        return false;
    }
}
