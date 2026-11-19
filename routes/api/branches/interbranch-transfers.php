<?php

use App\Http\Controllers\Api\Branches\InterbranchTransferController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Interbranch transfers and the branch counts (Phase 6 MB-BE5)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated group. Permissions
| are checked in the form requests. `{interbranchTransfer}` is bound only to
| a transfer whose source or destination the caller can see (404 otherwise)
| and is bounded to 18 digits, so `in-transit` can never be read as an id.
| `/branch-counts` is its own resource, never a fixed word beside
| `branches/{branch}`.
*/
Route::prefix('interbranch-transfers')->name('interbranch-transfers.')->group(function () {
    Route::get('/', [InterbranchTransferController::class, 'index'])->name('index');
    Route::post('/', [InterbranchTransferController::class, 'store'])->name('store');
    Route::get('/in-transit', [InterbranchTransferController::class, 'inTransit'])->name('in-transit');

    Route::prefix('{interbranchTransfer}')->where(['interbranchTransfer' => '[0-9]{1,18}'])->group(function () {
        Route::get('/', [InterbranchTransferController::class, 'show'])->name('show');
        Route::post('/approve', [InterbranchTransferController::class, 'approve'])->name('approve');
        Route::post('/reject', [InterbranchTransferController::class, 'reject'])->name('reject');
        Route::post('/cancel', [InterbranchTransferController::class, 'cancel'])->name('cancel');
        Route::post('/receive', [InterbranchTransferController::class, 'receive'])->name('receive');
        Route::post('/reverse', [InterbranchTransferController::class, 'reverse'])->name('reverse');
    });
});

Route::get('/branch-counts', [InterbranchTransferController::class, 'counts'])->name('branch-counts');
