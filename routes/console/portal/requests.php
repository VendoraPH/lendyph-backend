<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Member Portal requests (slice P3), on Manila time, after savings:expire
 * (00:30): requests of portal accounts already turned off are ended,
 * requests past their date expire with their holds, and withdrawals waiting
 * for a second approval follow it. Safe to run twice.
 */
Schedule::command('portal:requests-sweep')->dailyAt('00:45')->timezone('Asia/Manila')->withoutOverlapping(10);
