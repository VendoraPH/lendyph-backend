<?php

use Illuminate\Support\Facades\Schedule;

/*
 * AMLA detection (Phase 6, AMLA-B3), required from routes/console.php: the
 * detector every minute, one run at a time. It does nothing while AMLA
 * monitoring is off, and picks up whatever the harvester added since.
 */
Schedule::command('amla:detect')->everyMinute()->withoutOverlapping(5);
