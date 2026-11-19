<?php

use Illuminate\Support\Facades\Schedule;

/*
 * AMLA screening (Phase 6, AMLA-B5), required from routes/console.php: every
 * minute, one run at a time. It does nothing while AMLA monitoring is off.
 */
Schedule::command('amla:screen')->everyMinute()->withoutOverlapping(10);
