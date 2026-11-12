<?php

use App\Http\Controllers\Api\AccountingStatementController;
use App\Http\Controllers\Api\AccountingStatementNotesController;
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
| The notes' Enhanced PESOS rating (gap round B4) also needs
| `branches:view_all`, since CDA figures are cooperative-wide.
*/

Route::get('/statements/balance-sheet', [AccountingStatementController::class, 'balanceSheet']);
Route::get('/statements/income-statement', [AccountingStatementController::class, 'incomeStatement']);
Route::get('/statements/notes', [AccountingStatementNotesController::class, 'show']);
