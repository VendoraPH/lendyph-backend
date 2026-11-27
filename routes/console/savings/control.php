<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Savings & Deposits: the daily subledger-against-GL control (slice S4),
 * required from routes/console.php. At 23:50 Manila, after the day's
 * postings; it does nothing while there are no savings records.
 */
Schedule::command('savings:gl-control')->dailyAt('23:50')->timezone('Asia/Manila')->withoutOverlapping(10);
