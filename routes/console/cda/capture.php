<?php

use Illuminate\Support\Facades\Schedule;

/*
 * CDA Reports: the year-end loan capture (slice C1, design §7), required from
 * routes/console.php. At 23:58 Manila on Dec 31, after `portfolio:snapshot`
 * at 23:55: loan balances can't be read for a past date, so the year's aging,
 * required allowance and 1200 balances are kept on its reporting period. Safe
 * to run twice.
 */
Schedule::command('cda:capture-year-end')
    ->cron('58 23 31 12 *')
    ->timezone('Asia/Manila')
    ->withoutOverlapping(10);
