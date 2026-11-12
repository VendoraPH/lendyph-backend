<?php

use App\Http\Controllers\Api\Savings\SavingsAutoCreditRunController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Savings & Deposits: past auto-credit runs (gap round B7)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated group, with the other
| routes/api/savings/*.php files. Read only, `savings:view`, checked in the
| controller; branch-scoped. Ids are numbers.
*/
Route::prefix('savings')->group(function () {
    Route::get('/auto-credit/runs', [SavingsAutoCreditRunController::class, 'index']);
    Route::get('/auto-credit/runs/{run}', [SavingsAutoCreditRunController::class, 'show'])->whereNumber('run');
});
