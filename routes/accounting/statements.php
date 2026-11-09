<?php

use App\Http\Controllers\Api\AccountingStatementController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Financial statements from the server
|--------------------------------------------------------------------------
|
| The balance sheet and the income statement (Phase 2, decision 10), on
| `accounting:view` like the cash flow and equity changes statements beside
| them in routes/api.php. Required inside the /accounting group, so these
| paths are relative to /accounting and inherit its authenticated middleware.
*/

Route::get('/statements/balance-sheet', [AccountingStatementController::class, 'balanceSheet']);
Route::get('/statements/income-statement', [AccountingStatementController::class, 'incomeStatement']);
