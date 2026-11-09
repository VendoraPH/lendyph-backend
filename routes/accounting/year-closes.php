<?php

use App\Http\Controllers\Api\AccountingYearCloseController;
use Illuminate\Support\Facades\Route;

/*
 * The year-end close (Phase 2, round C, slice C3). The list is on
 * `accounting:view`; the preview, the close and the reopen need
 * `accounting:close` and `branches:view_all`. Required inside the accounting
 * group, so these are /api/accounting/year-closes/...
 */
Route::get('/year-closes', [AccountingYearCloseController::class, 'index']);
Route::get('/year-closes/preview', [AccountingYearCloseController::class, 'preview']);
Route::post('/year-closes', [AccountingYearCloseController::class, 'store']);
Route::post('/year-closes/{yearClose}/reopen', [AccountingYearCloseController::class, 'reopen'])->whereNumber('yearClose');
