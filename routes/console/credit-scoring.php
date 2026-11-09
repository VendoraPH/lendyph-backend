<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Credit Scoring: the module's scheduled commands. Required from
 * routes/console.php. Both are advisory only and never write a loan.
 *
 * - The nightly run scores every member at 00:45 Manila, after the overnight
 *   default (00:05), penalty (00:10) and past-due (00:20) jobs have left the
 *   day's lateness in place. The lock outlives a killed run by at most two
 *   hours, and it runs in the background so the minute jobs keep ticking.
 * - The minute job rescores members whose data changed; it budgets itself to
 *   45 seconds or 200 members, so a tick returns before the next is due. Its
 *   lock lasts five minutes, so a killed tick blocks the queue only briefly.
 */
Schedule::command('credit-scoring:recalculate')
    ->dailyAt('00:45')
    ->timezone('Asia/Manila')
    ->withoutOverlapping(120)
    ->runInBackground();

Schedule::command('credit-scoring:process-changes')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();
