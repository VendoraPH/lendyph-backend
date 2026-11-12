<?php

use App\Http\Controllers\Api\Savings\SavingsReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Savings & Deposits: reports (slice S4; gap round S5 adds the rest of TASK 36)
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

    // The rest of the Savings task's TASK 36 (gap round S5).
    Route::get('/accounts', [SavingsReportController::class, 'accounts']);
    Route::get('/deposits', [SavingsReportController::class, 'deposits']);
    Route::get('/withdrawals', [SavingsReportController::class, 'withdrawals']);
    Route::get('/large-transactions', [SavingsReportController::class, 'largeTransactions']);
    Route::get('/time-deposits', [SavingsReportController::class, 'timeDeposits']);
    Route::get('/interest-accrual', [SavingsReportController::class, 'interestAccrual']);
    Route::get('/account-activity', [SavingsReportController::class, 'accountActivity']);
    Route::get('/member-statement/{borrower}', [SavingsReportController::class, 'memberStatement'])->whereNumber('borrower');
    Route::get('/summary', [SavingsReportController::class, 'summary']);

    Route::middleware('throttle:exports')->group(function () {
        Route::get('/balances/export', [SavingsReportController::class, 'exportBalances']);
        Route::get('/transactions/export', [SavingsReportController::class, 'exportTransactions']);
        Route::get('/time-deposit-maturities/export', [SavingsReportController::class, 'exportTimeDepositMaturities']);
        Route::get('/interest-postings/export', [SavingsReportController::class, 'exportInterestPostings']);
        Route::get('/dormant/export', [SavingsReportController::class, 'exportDormant']);
        Route::get('/gl-control/export', [SavingsReportController::class, 'exportGlControl']);
        Route::get('/accounts/export', [SavingsReportController::class, 'exportAccounts']);
        Route::get('/deposits/export', [SavingsReportController::class, 'exportDeposits']);
        Route::get('/withdrawals/export', [SavingsReportController::class, 'exportWithdrawals']);
        Route::get('/large-transactions/export', [SavingsReportController::class, 'exportLargeTransactions']);
        Route::get('/time-deposits/export', [SavingsReportController::class, 'exportTimeDeposits']);
        Route::get('/interest-accrual/export', [SavingsReportController::class, 'exportInterestAccrual']);
        Route::get('/account-activity/export', [SavingsReportController::class, 'exportAccountActivity']);
        Route::get('/member-statement/{borrower}/export', [SavingsReportController::class, 'exportMemberStatement'])->whereNumber('borrower');
        Route::get('/summary/export', [SavingsReportController::class, 'exportSummary']);
    });
});
