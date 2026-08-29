<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Check for defaulted loans daily at 6 AM
Schedule::command('loans:check-defaulted')->dailyAt('06:00');

// Apply penalties on overdue schedules daily at 6:05 AM
Schedule::command('loans:apply-penalties')->dailyAt('06:05');

// Database backup daily at 2 AM, keep last 7
Schedule::command('db:backup --keep=7')->dailyAt('02:00');

// Drop anonymous registrations that can never be completed, and submission
// tokens well past their 15-minute life. Only touches pending submissions with
// no valid ID — the ones with documents attached are the operator review queue,
// not abandonment.
Schedule::command('registrations:prune')->dailyAt('03:30');

/*
 * Advance any in-flight CSV migration.
 *
 * SCHEDULED RATHER THAN QUEUED, ON PURPOSE. `QUEUE_CONNECTION=database` and the
 * `jobs` tables exist and are empty — because there is no queue worker running
 * on any of the five deployments. No `queue:work`, no Horizon, no supervisor
 * program. A dispatched job would insert a row into `jobs` and stay there
 * forever while the import screen polled a run that never moved: no error, no
 * failed job, no log line. `schedule:run` is already in root cron every minute
 * on all five boxes, so this is the only background mechanism on these servers
 * that actually runs.
 *
 * Every minute because an import is a foreground activity from the admin's
 * point of view — they are watching a progress bar — and the command budgets
 * itself to ~50 seconds so each tick returns before the next one is due.
 *
 * withoutOverlapping(10) rather than the default: the lock has to outlive a
 * tick that is killed mid-chunk (a deploy, an OOM), or the next minute's tick
 * would start a second importer against the same rows. Ten minutes is the
 * ceiling on how long a wedged tick can block the queue of ticks behind it, and
 * the budget is what keeps a healthy tick nowhere near it.
 */
Schedule::command('imports:process')->everyMinute()->withoutOverlapping(10);

// Blank the personal data staged in csv_import_rows once an import run has been
// finished for a month. The uploaded CSV is deleted the moment a run closes, but
// staging had already copied every member's name, birthdate, contact number and
// income into those rows, and nothing removed them. Runs at 03:45, after the
// registration prune, so the two retention jobs never overlap on the same box.
Schedule::command('imports:redact-rows')->dailyAt('03:45');
