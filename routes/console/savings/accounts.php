<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Savings accounts' nightly jobs (slice S2), on Manila time, after the
 * lending jobs. Each does nothing when there are no savings rows, and is safe
 * to run twice: every update is conditioned on the row's status under its
 * lock.
 */

// Holds past `expires_on` and requests past the end of their day.
Schedule::command('savings:expire')->dailyAt('00:30')->timezone('Asia/Manila')->withoutOverlapping(10);

// Active accounts with no member activity for `dormancy_months` (24).
Schedule::command('savings:mark-dormant')->dailyAt('00:35')->timezone('Asia/Manila')->withoutOverlapping(10);
