<?php

use App\Http\Controllers\Api\AccountingOpeningBalanceController;
use Illuminate\Support\Facades\Route;

/*
 * Opening balances (Phase 2, Accounting round C, slice C2): one entry for the
 * whole organisation, a draft until it is finalised, which posts it once.
 * Every route needs branches:view_all as well as its own permission
 * (accounting:view; accounting:settings; and journals:post to finalise).
 * Required inside the accounting group, so these are /api/accounting/opening-balances.
 */
Route::get('/opening-balances', [AccountingOpeningBalanceController::class, 'show']);
Route::put('/opening-balances', [AccountingOpeningBalanceController::class, 'update']);
Route::post('/opening-balances/finalise', [AccountingOpeningBalanceController::class, 'finalise']);
