<?php

namespace App\Services\LoanOrigination\Intake;

use App\Services\Reminders\AllowList;
use App\Services\Reminders\Drivers\DriverResolver;
use App\Services\Reminders\Drivers\ReminderDriver;

/**
 * How an applicant's code would be texted (origination decision 6): through
 * the reminder SMS driver, and only to a number the reminders allow-list
 * permits, as the portal's OTP is. Today every driver reaches nobody (`log`,
 * `fake`), so no code is ever sent and no link takes an application outside
 * the staging test-code mode.
 *
 * A staff account's mobile number counts only for that mode, whose codes are
 * shown on screen and never sent (TestCodes); it never widens who is texted.
 */
class ApplicantSms
{
    public function driver(): ReminderDriver
    {
        return app(DriverResolver::class)->for('sms');
    }

    /** A driver that actually delivers is set up. */
    public function reachesSomeone(): bool
    {
        return ! $this->driver()->reachesNobody();
    }

    public function permits(string $mobile): bool
    {
        return app(AllowList::class)->permits('sms', $mobile);
    }
}
