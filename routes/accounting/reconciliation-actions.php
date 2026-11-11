<?php

use App\Http\Controllers\Api\AccountingReconciliationActionController;
use Illuminate\Support\Facades\Route;

/*
 * Reconciliation actions (Phase 2, Accounting round C, C5): an unmatched
 * statement line recorded as an expense, a transfer or a suspense entry, and
 * matched in the same step. Each needs `accounting:reconcile`,
 * `branches:view_all` and its own record's permission (checked in the form
 * requests). Required inside the accounting group, so these are
 * /api/accounting/reconciliations/{reconciliation}/lines/{line}/….
 */
Route::post(
    '/reconciliations/{reconciliation}/lines/{line}/expense',
    [AccountingReconciliationActionController::class, 'expense'],
)->whereNumber(['reconciliation', 'line']);

Route::post(
    '/reconciliations/{reconciliation}/lines/{line}/transfer',
    [AccountingReconciliationActionController::class, 'transfer'],
)->whereNumber(['reconciliation', 'line']);

Route::post(
    '/reconciliations/{reconciliation}/lines/{line}/suspense',
    [AccountingReconciliationActionController::class, 'suspense'],
)->whereNumber(['reconciliation', 'line']);
