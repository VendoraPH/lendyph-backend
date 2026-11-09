<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Payment reminders: the reminders module's scheduled commands. Required from
 * routes/console.php. Manila time. Nothing here sends anything: the drivers
 * are log-only (config/reminders.php).
 */

// Plan the coming days' reminders and cancel the ones no longer needed,
// hourly at :30 (the first at 00:30, after the overnight loan jobs). The
// 30-minute lock outlives a run killed mid-plan.
Schedule::command('reminders:plan')->hourlyAt(30)->timezone('Asia/Manila')->withoutOverlapping(30);

// Hand due reminders to their driver. Each run stops after 50 seconds or 200
// reminders, so it ends before the next minute's; the 10-minute lock is the
// ceiling on how long a wedged run can block the ones behind it.
Schedule::command('reminders:dispatch')->everyMinute()->runInBackground()->withoutOverlapping(10);

// Blank reminder text two years after the loan closed, after the other
// retention jobs (03:45, 04:00).
Schedule::command('reminders:redact-messages')->dailyAt('04:15')->timezone('Asia/Manila');
