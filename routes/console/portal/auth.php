<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Member Portal schedules: sign-in history (slice P1)
|--------------------------------------------------------------------------
|
| Required from routes/console.php with the other routes/console/portal/*.php
| files. Runs on Asia/Manila time.
*/
Schedule::command('portal:prune-login-events')->dailyAt('04:10')->timezone('Asia/Manila')->withoutOverlapping(10);
