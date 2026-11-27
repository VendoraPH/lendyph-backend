<?php

namespace Tests\Feature;

use DateTimeZone;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every job that runs at a set time of day names Asia/Manila itself, so it
 * keeps Manila time on a box whose scheduler default is another zone (no
 * APP_TIMEZONE, or app.schedule_timezone set to UTC). Every-minute jobs have
 * no time of day to keep.
 */
class ScheduleTimezoneTest extends TestCase
{
    public function test_every_time_of_day_job_keeps_manila_time_when_the_default_zone_is_utc(): void
    {
        $schedule = new Schedule('UTC');
        $this->app->instance(Schedule::class, $schedule);
        ScheduleFacade::clearResolvedInstance();

        require base_path('routes/console.php');

        $zones = collect($schedule->events())
            ->reject(fn (Event $event) => $event->expression === '* * * * *')
            ->mapWithKeys(fn (Event $event) => [
                Str::after((string) $event->command, "'artisan' ") => $event->timezone instanceof DateTimeZone
                    ? $event->timezone->getName()
                    : (string) $event->timezone,
            ]);

        $this->assertNotEmpty($zones, 'routes/console.php registered no time-of-day jobs.');
        $this->assertSame(
            [],
            $zones->reject(fn (string $zone) => $zone === 'Asia/Manila')->all(),
            'These jobs follow the server default zone instead of naming Asia/Manila.',
        );
    }
}
