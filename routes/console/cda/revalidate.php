<?php

use Illuminate\Support\Facades\Schedule;

/*
 * CDA Reports: the nightly refresh of draft reports (slice C2, design §4),
 * required from routes/console.php. At 01:10 Manila, after the overnight
 * loan jobs (00:05–00:20) and the CDA reminders (00:50), so the dashboard's
 * readiness and issues are current each morning.
 */
Schedule::command('cda:revalidate')
    ->dailyAt('01:10')
    ->timezone('Asia/Manila')
    ->withoutOverlapping(60);
