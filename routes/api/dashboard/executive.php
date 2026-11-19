<?php

use App\Http\Controllers\Api\Dashboard\ExecutiveDashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Executive dashboard: cards, branches and PAR loans (Phase 6 EB-D1)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated group. Each needs
| `dashboard:analytics` (its request); each figure also needs its source's
| permission. EB-D2's books, trends and alerts are in executive-books.php.
*/
Route::prefix('dashboard/executive')->group(function () {
    Route::get('/overview', [ExecutiveDashboardController::class, 'overview'])->name('dashboard.executive.overview');
    Route::get('/branches', [ExecutiveDashboardController::class, 'branches'])->name('dashboard.executive.branches');
    Route::get('/par-loans', [ExecutiveDashboardController::class, 'parLoans'])->name('dashboard.executive.par-loans');
});
