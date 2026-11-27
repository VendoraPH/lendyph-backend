<?php

namespace Tests\Unit\Linda;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase as FrameworkTestCase;
use Illuminate\Support\Carbon;

/**
 * Boots the application, so `config('app.timezone')` is the real setting,
 * and touches no database: Linda's question understanding reads none.
 */
abstract class LindaTestCase extends FrameworkTestCase
{
    /**
     * Freeze the clock at a Manila wall-clock time, handed over as the UTC
     * instant it is. At 00:30 Manila the UTC date is still the day before, so
     * code that reads the date in UTC gets the wrong day.
     */
    protected function atManila(string $dateTime): void
    {
        $instant = CarbonImmutable::parse($dateTime, 'Asia/Manila')->utc();

        Carbon::setTestNow($instant);
        CarbonImmutable::setTestNow($instant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }
}
