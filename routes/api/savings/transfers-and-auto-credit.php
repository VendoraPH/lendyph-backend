<?php

use App\Http\Controllers\Api\Savings\SavingsAutoCreditController;
use App\Http\Controllers\Api\Savings\SavingsShareCapitalTransferController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Savings & Deposits: transfers to share capital and auto-credit (gap round S3)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated group, with the other
| routes/api/savings/*.php files. Permissions are checked in the controller
| and form requests: a transfer needs `savings:post` and
| `share_capital:create`; instructions are read with `savings:view` and
| written with `savings:post`; a run is previewed and processed with
| `savings:post`. Money posts need an Idempotency-Key header; ids are
| numbers.
*/
Route::prefix('savings')->group(function () {
    Route::post('/accounts/{account}/share-capital-transfers', [SavingsShareCapitalTransferController::class, 'store'])->whereNumber('account');

    Route::get('/auto-credit/instructions', [SavingsAutoCreditController::class, 'index']);
    Route::post('/auto-credit/instructions', [SavingsAutoCreditController::class, 'store']);
    Route::get('/auto-credit/instructions/{instruction}', [SavingsAutoCreditController::class, 'show'])->whereNumber('instruction');
    Route::put('/auto-credit/instructions/{instruction}', [SavingsAutoCreditController::class, 'update'])->whereNumber('instruction');
    Route::delete('/auto-credit/instructions/{instruction}', [SavingsAutoCreditController::class, 'destroy'])->whereNumber('instruction');

    Route::get('/auto-credit/preview', [SavingsAutoCreditController::class, 'preview']);
    Route::post('/auto-credit/process', [SavingsAutoCreditController::class, 'process']);
});
