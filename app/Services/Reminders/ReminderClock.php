<?php

namespace App\Services\Reminders;

use App\Models\ReminderSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Reminders run on Manila time, whatever the server's clock or the app's
 * timezone. Times are worked out in Manila and stored in the app timezone.
 */
final class ReminderClock
{
    public static function manila(?CarbonInterface $at = null): Carbon
    {
        return Carbon::instance($at ?? now())->setTimezone(ReminderSetting::TIMEZONE);
    }

    public static function today(?CarbonInterface $at = null): Carbon
    {
        return self::manila($at)->startOfDay();
    }

    public static function toApp(CarbonInterface $at): Carbon
    {
        return Carbon::instance($at)->setTimezone(config('app.timezone'));
    }
}
