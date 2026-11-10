<?php

use App\Http\Controllers\Api\Savings\SavingsInterestRunController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Savings & Deposits: interest runs (slice S4)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated group, with the other
| routes/api/savings/*.php files. Permissions are checked in the controller
| and form requests: `savings:view` reads, `savings:post` previews, creates
| and posts, `savings:approve` approves, rejects and reverses.
*/
Route::prefix('savings/interest-runs')->group(function () {
    Route::get('/', [SavingsInterestRunController::class, 'index']);
    Route::post('/preview', [SavingsInterestRunController::class, 'preview']);
    Route::post('/', [SavingsInterestRunController::class, 'store']);
    Route::get('/{run}', [SavingsInterestRunController::class, 'show'])->whereNumber('run');
    Route::post('/{run}/approve', [SavingsInterestRunController::class, 'approve'])->whereNumber('run');
    Route::post('/{run}/reject', [SavingsInterestRunController::class, 'reject'])->whereNumber('run');
    Route::post('/{run}/post', [SavingsInterestRunController::class, 'post'])->whereNumber('run');
    Route::post('/{run}/reverse', [SavingsInterestRunController::class, 'reverse'])->whereNumber('run');
});
