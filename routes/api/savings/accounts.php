<?php

use App\Http\Controllers\Api\SavingsAccountController;
use App\Http\Controllers\Api\SavingsApprovalController;
use App\Http\Controllers\Api\SavingsHoldController;
use App\Http\Controllers\Api\SavingsTransactionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Savings & Deposits: accounts, the counter, holds, the register, reversals
| and approvals (slice S2)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated group, with the other
| routes/api/savings/*.php files. Permissions are checked in the controllers
| and form requests; another branch's record answers 404. Every money post
| needs an Idempotency-Key header.
*/
Route::prefix('savings')->group(function () {
    Route::get('/accounts', [SavingsAccountController::class, 'index']);
    Route::post('/accounts', [SavingsAccountController::class, 'store']);
    Route::get('/accounts/{account}', [SavingsAccountController::class, 'show'])->whereNumber('account');
    Route::get('/accounts/{account}/transactions', [SavingsAccountController::class, 'transactions'])->whereNumber('account');
    Route::post('/accounts/{account}/deposits', [SavingsAccountController::class, 'deposit'])->whereNumber('account');
    Route::post('/accounts/{account}/withdrawals', [SavingsAccountController::class, 'withdraw'])->whereNumber('account');
    Route::post('/accounts/{account}/transfers', [SavingsAccountController::class, 'transfer'])->whereNumber('account');
    Route::get('/accounts/{account}/closing-quote', [SavingsAccountController::class, 'closingQuote'])->whereNumber('account');
    Route::post('/accounts/{account}/close', [SavingsAccountController::class, 'close'])->whereNumber('account');
    Route::post('/accounts/{account}/status', [SavingsAccountController::class, 'status'])->whereNumber('account');

    Route::get('/accounts/{account}/holds', [SavingsHoldController::class, 'index'])->whereNumber('account');
    Route::post('/accounts/{account}/holds', [SavingsHoldController::class, 'store'])->whereNumber('account');
    Route::post('/holds/{hold}/release', [SavingsHoldController::class, 'release'])->whereNumber('hold');

    Route::get('/transactions', [SavingsTransactionController::class, 'index']);
    Route::get('/transactions/{transaction}', [SavingsTransactionController::class, 'show'])->whereNumber('transaction');
    Route::post('/transactions/{transaction}/reverse', [SavingsTransactionController::class, 'reverse'])->whereNumber('transaction');

    Route::get('/approvals', [SavingsApprovalController::class, 'index']);
    Route::post('/approvals/{approval}/approve', [SavingsApprovalController::class, 'approve'])->whereNumber('approval');
    Route::post('/approvals/{approval}/reject', [SavingsApprovalController::class, 'reject'])->whereNumber('approval');
    Route::post('/approvals/{approval}/cancel', [SavingsApprovalController::class, 'cancel'])->whereNumber('approval');
});
