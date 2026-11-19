<?php

use Illuminate\Support\Facades\Schedule;

/*
 * AMLA report exports (Phase 6, AMLA-B7), required from routes/console.php:
 * every 15 minutes, delete the files of exports whose download link has
 * expired. Each export's record and checksum stay.
 */
Schedule::command('amla:prune-exports')->everyFifteenMinutes()->withoutOverlapping(10);
