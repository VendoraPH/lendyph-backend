<?php

use App\Http\Controllers\Api\Amla\AmlaChangeRequestController;
use App\Http\Controllers\Api\Amla\AmlaFoundationController;
use App\Http\Controllers\Api\Amla\AmlaLogController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AMLA foundation (Phase 6, AMLA-B1)
|--------------------------------------------------------------------------
|
| Loaded by routes/api.php inside the authenticated staff group. Each route
| checks its own `amla:*` key (AmlaAccess, in the controllers and form
| requests); settings, change requests and the AMLA log also need
| `branches:view_all`. Every route is named `amla.*` and passes the `amla`
| limiter (AmlaServiceProvider): a caller holding no `amla:*` key gets 10
| refusals a minute, so refusals can't flood the log, which is kept forever.
| Change requests are found by `{id}` only after the permission check, so a
| caller without access can't tell which ids exist.
*/
Route::prefix('amla')->name('amla.')->middleware('throttle:amla')->group(function () {
    Route::get('/status', [AmlaFoundationController::class, 'status'])->name('status');
    Route::get('/settings', [AmlaFoundationController::class, 'settings'])->name('settings');
    Route::get('/settings/versions', [AmlaFoundationController::class, 'settingsVersions'])->name('settings.versions');
    Route::get('/calendar', [AmlaFoundationController::class, 'calendar'])->name('calendar');
    Route::get('/deadlines/preview', [AmlaFoundationController::class, 'deadlinePreview'])->name('deadlines.preview');

    Route::get('/change-requests', [AmlaChangeRequestController::class, 'index'])->name('change-requests.index');
    Route::post('/change-requests', [AmlaChangeRequestController::class, 'store'])->name('change-requests.store');
    Route::get('/change-requests/{id}', [AmlaChangeRequestController::class, 'show'])->whereNumber('id')->name('change-requests.show');
    Route::post('/change-requests/{id}/approve', [AmlaChangeRequestController::class, 'approve'])->whereNumber('id')->name('change-requests.approve');
    Route::post('/change-requests/{id}/reject', [AmlaChangeRequestController::class, 'reject'])->whereNumber('id')->name('change-requests.reject');
    Route::post('/change-requests/{id}/withdraw', [AmlaChangeRequestController::class, 'withdraw'])->whereNumber('id')->name('change-requests.withdraw');

    Route::get('/log', [AmlaLogController::class, 'index'])->name('log');
});
