<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Savings & Deposits: the nightly in-app notices (gap round S4). At 00:50
 * Manila, after the time deposit maturity job (00:40): time deposits maturing
 * at each notice day and accounts approaching dormancy. Safe to run twice.
 */
Schedule::command('savings:notices')->dailyAt('00:50')->timezone('Asia/Manila')->withoutOverlapping(10);
