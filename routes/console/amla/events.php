<?php

use Illuminate\Support\Facades\Schedule;

/*
 * AMLA events (Phase 6, AMLA-B2), required from routes/console.php. Both do
 * nothing while AMLA monitoring is off.
 * - The harvester every minute, like the credit scoring change scan; one run
 *   at a time.
 * - The coverage check for the day before at 00:45 Manila, after the
 *   overnight loan jobs.
 */
Schedule::command('amla:harvest')->everyMinute()->withoutOverlapping(5);
Schedule::command('amla:coverage-check')->dailyAt('00:45')->timezone('Asia/Manila')->withoutOverlapping(30);
