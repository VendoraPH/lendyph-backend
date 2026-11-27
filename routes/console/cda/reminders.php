<?php

use Illuminate\Support\Facades\Schedule;

/*
 * CDA Reports: deadline reminders (slice C4, design §10), required from
 * routes/console.php. At 00:50 Manila, after the overnight lending jobs:
 * the milestone crossed today for each open report (30, 15, 7 and 3 days
 * before, the due day, overdue), each sent once. In-app only.
 */
Schedule::command('cda:send-reminders')
    ->dailyAt('00:50')
    ->timezone('Asia/Manila')
    ->withoutOverlapping(30);
