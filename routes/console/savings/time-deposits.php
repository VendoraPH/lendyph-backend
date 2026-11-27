<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Savings & Deposits: time deposit maturity (slice S3, design §6). At 00:40
 * Manila, after the holds and approvals expire (00:30) and dormancy (00:35):
 * due placements move to matured, and only what moves no cash settles by
 * itself. Required from routes/console.php with the other savings schedules.
 */
Schedule::command('savings:mature-time-deposits')
    ->dailyAt('00:40')
    ->timezone('Asia/Manila')
    ->withoutOverlapping(10);
