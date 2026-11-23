<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Online applications (Phase 6, LO-B3): expiry and retention, hourly at :25
 * on Manila time, so a draft reads Incomplete within the hour after its 24
 * hours without a save. Safe to run twice.
 */
Schedule::command('loan-origination:retention')->hourlyAt(25)->timezone('Asia/Manila')->withoutOverlapping(10);
