<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The overnight loan jobs run just after midnight Manila, so the day's
// defaulted flags and stamped penalties match "today" from the start of the
// working day rather than from 6 AM. In order: defaults, penalties, then the
// past-due notifications, which read the state the first two leave.
Schedule::command('loans:check-defaulted')->dailyAt('00:05')->timezone('Asia/Manila');

Schedule::command('loans:apply-penalties')->dailyAt('00:10')->timezone('Asia/Manila');

// Tell each newly past-due loan's officer, once per past-due episode.
Schedule::command('notifications:past-due')->dailyAt('00:20')->timezone('Asia/Manila');

// Record the day's portfolio arrears (overdue count and amount, total
// outstanding, PAR 1/30/90) at the end of the Manila day. The dashboard's
// overdue sparkline reads its past days from these rows.
Schedule::command('portfolio:snapshot')->dailyAt('23:55')->timezone('Asia/Manila');

// CIC reporting: freeze every reportable record's figures at 23:58 Manila on
// each month's last day (plan decision 2). Does nothing while CIC is off, and
// leaves a month end that already has a complete capture as it is.
Schedule::command('cic:capture-month-end')->lastDayOfMonth('23:58')->timezone('Asia/Manila');

// CIC reporting: remove encryption temporary folders a killed run left
// behind, once over an hour old. Each run removes its own folder when it ends.
Schedule::command('cic:clear-temp')->hourly()->timezone('Asia/Manila');

// Database backup daily at 2 AM, keep last 7
Schedule::command('db:backup --keep=7')->dailyAt('02:00')->timezone('Asia/Manila');

// Drop anonymous registrations that can never be completed, and submission
// tokens well past their 15-minute life. Only touches pending submissions with
// no valid ID — the ones with documents attached are the operator review queue,
// not abandonment.
Schedule::command('registrations:prune')->dailyAt('03:30')->timezone('Asia/Manila');

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
 * withoutOverlapping(1): a cheap first filter, not the guard. A tick killed
 * mid-chunk (a deploy, an OOM) never removes this lock, and while it stands no
 * tick runs; at ten minutes it froze the run far past the import screen's
 * 180-second stale warning ("stuck at step 6", ClickUp 14zfbg0dpj2). Two
 * importers are kept apart by the command's own MySQL named lock instead,
 * which the server frees the moment a dead tick's session ends, so this one
 * may lapse after a minute: a tick still running past it makes the next one
 * stand down, and a dead one blocks for a minute at most.
 */
Schedule::command('imports:process')->everyMinute()->withoutOverlapping(1);

// Blank the personal data staged in csv_import_rows once an import run has been
// finished for a month. The uploaded CSV is deleted the moment a run closes, but
// staging had already copied every member's name, birthdate, contact number and
// income into those rows, and nothing removed them. Runs at 03:45, after the
// registration prune, so the two retention jobs never overlap on the same box.
//
// The month is counted from the run FINISHING, not from the upload — so a run an
// admin abandoned holds its rows until `imports:process` writes it off at
// --prune-days=14, and only then starts the 30. Worst case is ~44 days, not 30.
Schedule::command('imports:redact-rows')->dailyAt('03:45')->timezone('Asia/Manila');

// Notification retention: read notifications after 90 days, any notification
// after 365, in chunks. After the registration prune and the import redaction,
// so the retention jobs never overlap.
Schedule::command('notifications:prune')->dailyAt('04:00')->timezone('Asia/Manila');

// Module schedules, one file per module, so a module adds its own schedule
// without editing this file. RouteSnapshotTest pins every schedule.
require __DIR__.'/console/reminders.php';
require __DIR__.'/console/credit-scoring.php';
require __DIR__.'/console/linda.php';

/*
 * Savings & Deposits schedules (routes/console/savings/*.php), in file-name
 * order: each savings slice adds its own file. All run on Asia/Manila time.
 */
$savingsSchedules = glob(__DIR__.'/console/savings/*.php') ?: [];
sort($savingsSchedules);

foreach ($savingsSchedules as $savingsSchedule) {
    require $savingsSchedule;
}

/*
 * CDA Reports schedules (routes/console/cda/*.php), in file-name order: each
 * CDA slice adds its own file. All run on Asia/Manila time.
 */
$cdaSchedules = glob(__DIR__.'/console/cda/*.php') ?: [];
sort($cdaSchedules);

foreach ($cdaSchedules as $cdaSchedule) {
    require $cdaSchedule;
}

/*
 * Member Portal schedules (routes/console/portal/*.php), in file-name order:
 * each portal slice adds its own file. All run on Asia/Manila time.
 */
$portalSchedules = glob(__DIR__.'/console/portal/*.php') ?: [];
sort($portalSchedules);

foreach ($portalSchedules as $portalSchedule) {
    require $portalSchedule;
}

/*
 * Phase 6 schedules: AMLA (routes/console/amla/*.php) and loan origination
 * (routes/console/loan-origination/*.php), in that order and each in file-name
 * order: each slice adds its own file. All run on Asia/Manila time.
 */
foreach (['amla', 'loan-origination'] as $phase6Module) {
    $phase6Schedules = glob(__DIR__.'/console/'.$phase6Module.'/*.php') ?: [];
    sort($phase6Schedules);

    foreach ($phase6Schedules as $phase6Schedule) {
        require $phase6Schedule;
    }
}
