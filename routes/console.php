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

// Blank the personal data staged in csv_import_rows once an import run has been
// finished for a month. The uploaded CSV is deleted the moment a run closes, but
// staging had already copied every member's name, birthdate, contact number and
// income into those rows, and nothing removed them. Runs at 03:45, after the
// registration prune, so the two retention jobs never overlap on the same box.
Schedule::command('imports:redact-rows')->dailyAt('03:45');
