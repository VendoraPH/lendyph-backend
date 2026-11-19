<?php

use App\Http\Controllers\Api\Dashboard\ExecutiveBooksController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Executive dashboard: books, trends and alerts (Phase 6 EB-D2)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated group, with the other
| routes/api/dashboard/*.php files. Every route needs `dashboard:analytics`
| (ExecutiveBooksRequest) plus its sources' permissions, checked in the
| controller.
*/
Route::prefix('dashboard/executive')->group(function () {
    Route::get('/trends', [ExecutiveBooksController::class, 'trends'])->name('dashboard.executive.trends');
    Route::get('/attention', [ExecutiveBooksController::class, 'attention'])->name('dashboard.executive.attention');
    Route::get('/turnaround', [ExecutiveBooksController::class, 'turnaround'])->name('dashboard.executive.turnaround');
    Route::get('/share-capital-control', [ExecutiveBooksController::class, 'shareCapitalControl'])->name('dashboard.executive.share-capital-control');
});
