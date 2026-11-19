<?php

use App\Http\Controllers\Api\Branches\BranchClosureController;
use App\Http\Controllers\Api\Branches\BranchStatusController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Branches: status, main office and closure (Phase 6 MB-BE1)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated group. Every path is
| nested under {branch}, never a fixed word beside `branches/{branch}`.
| Each needs `branches:manage` plus `branches:view_all` (BranchStatusRequest);
| a branch the caller can't see answers 404.
*/
Route::prefix('branches/{branch}')->whereNumber('branch')->group(function () {
    Route::patch('/status', [BranchStatusController::class, 'update'])->name('branches.status');
    Route::put('/main-office', [BranchStatusController::class, 'mainOffice'])->name('branches.main-office');

    Route::get('/closure', [BranchClosureController::class, 'show'])->name('branches.closure.show');
    Route::post('/closure', [BranchClosureController::class, 'store'])->name('branches.closure.store');
    Route::delete('/closure', [BranchClosureController::class, 'destroy'])->name('branches.closure.cancel');
    Route::post('/closure/approve', [BranchClosureController::class, 'approve'])->name('branches.closure.approve');
    Route::post('/closure/reject', [BranchClosureController::class, 'reject'])->name('branches.closure.reject');
});
