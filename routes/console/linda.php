<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Linda: the question log's retention. Required from routes/console.php.
 * 04:20 Manila, after the other retention jobs (notifications at 04:00,
 * reminder text at 04:15), so they never overlap.
 */
Schedule::command('linda:prune')->dailyAt('04:20')->timezone('Asia/Manila');
