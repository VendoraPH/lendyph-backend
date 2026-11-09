<?php

use App\Http\Controllers\Api\AccountingLoanAccountingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Loan Accounting
|--------------------------------------------------------------------------
|
| Receivable aging by loan and by member, and the summary beside it, on
| `accounting:view` (checked in the controller). Required inside the
| /accounting group, so these paths are relative to /accounting.
*/
Route::get('/loan-accounting/summary', [AccountingLoanAccountingController::class, 'summary']);
Route::get('/loan-accounting/loans', [AccountingLoanAccountingController::class, 'loans']);
