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
