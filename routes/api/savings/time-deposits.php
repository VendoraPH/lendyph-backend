<?php

use App\Http\Controllers\Api\SavingsTimeDepositController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Savings & Deposits: time deposits (slice S3)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated group, with the other
| routes/api/savings/*.php files. Permissions are checked in the controller
| and form requests: `savings:view` (list, show), `savings:create` (quote,
| place, the next cash TD number), `savings:transact` (settle) and
| `savings:close` (pre-termination). The literals `quote` and
| `cash-reference/next` come before the `{timeDeposit}` routes.
*/
Route::prefix('savings/time-deposits')->group(function () {
    Route::get('/', [SavingsTimeDepositController::class, 'index']);
    Route::post('/quote', [SavingsTimeDepositController::class, 'quote']);
    Route::get('/cash-reference/next', [SavingsTimeDepositController::class, 'nextCashReference']);
    Route::post('/', [SavingsTimeDepositController::class, 'store']);
    Route::get('/{timeDeposit}', [SavingsTimeDepositController::class, 'show'])->whereNumber('timeDeposit');
    Route::post('/{timeDeposit}/settle', [SavingsTimeDepositController::class, 'settle'])->whereNumber('timeDeposit');
    Route::get('/{timeDeposit}/pretermination-quote', [SavingsTimeDepositController::class, 'preterminationQuote'])->whereNumber('timeDeposit');
    Route::post('/{timeDeposit}/preterminate', [SavingsTimeDepositController::class, 'preterminate'])->whereNumber('timeDeposit');
});
