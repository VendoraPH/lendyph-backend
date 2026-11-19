<?php

use Illuminate\Support\Facades\Schedule;

/*
 * AMLA filing deadlines (Phase 6, AMLA-B6), required from routes/console.php:
 * at 07:30 Manila, before the working day, compliance staff get AMLA inbox
 * items for filings due soon, due today or overdue. Never the notification
 * bell, SMS or email.
 */
Schedule::command('amla:deadlines')->dailyAt('07:30')->timezone('Asia/Manila')->withoutOverlapping(30);
