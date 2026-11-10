<?php

use Illuminate\Support\Facades\Schedule;

/*
 * CDA Reports: the register notices (slice C3, design §10), required from
 * routes/console.php. At 00:55 Manila, after the deadline reminders at 00:50:
 * documents expiring within 30 days, and officers missing a training their
 * position requires. In-app only, each sent once.
 */
Schedule::command('cda:register-notices')
    ->dailyAt('00:55')
    ->timezone('Asia/Manila')
    ->withoutOverlapping(10);
