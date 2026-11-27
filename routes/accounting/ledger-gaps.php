<?php

use App\Http\Controllers\Api\AccountingLedgerGapsController;
use Illuminate\Support\Facades\Route;

/*
 * Ledger gaps (Phase 2, Accounting decision 13), on `accounting:view`:
 * past events that posted nothing or posted differently. Read only.
 * Required inside the accounting group, so this is /api/accounting/ledger-gaps.
 */
Route::get('/ledger-gaps', [AccountingLedgerGapsController::class, 'index']);
