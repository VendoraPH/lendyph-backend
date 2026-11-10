<?php

use App\Http\Controllers\Api\Savings\SavingsReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Savings & Deposits: reports (slice S4)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated group, with the other
| routes/api/savings/*.php files. `reports:view` plus `savings:view`, checked
| in the form requests; the CSV exports need `reports:export` and share the
| exports rate limit.
*/
Route::prefix('reports/savings')->group(function () {
    Route::get('/balances', [SavingsReportController::class, 'balances']);
    Route::get('/transactions', [SavingsReportController::class, 'transactions']);
    Route::get('/time-deposit-maturities', [SavingsReportController::class, 'timeDepositMaturities']);
    Route::get('/interest-postings', [SavingsReportController::class, 'interestPostings']);
    Route::get('/dormant', [SavingsReportController::class, 'dormant']);
    Route::get('/statement/{account}', [SavingsReportController::class, 'statement'])->whereNumber('account');
    Route::get('/gl-control', [SavingsReportController::class, 'glControl']);

    Route::middleware('throttle:exports')->group(function () {
        Route::get('/balances/export', [SavingsReportController::class, 'exportBalances']);
        Route::get('/transactions/export', [SavingsReportController::class, 'exportTransactions']);
        Route::get('/time-deposit-maturities/export', [SavingsReportController::class, 'exportTimeDepositMaturities']);
        Route::get('/interest-postings/export', [SavingsReportController::class, 'exportInterestPostings']);
        Route::get('/dormant/export', [SavingsReportController::class, 'exportDormant']);
        Route::get('/gl-control/export', [SavingsReportController::class, 'exportGlControl']);
    });
});
