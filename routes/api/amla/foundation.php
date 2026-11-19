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
| `branches:view_all`. Literal segments come before the {changeRequest} id.
*/
Route::prefix('amla')->group(function () {
    Route::get('/status', [AmlaFoundationController::class, 'status']);
    Route::get('/settings', [AmlaFoundationController::class, 'settings']);
    Route::get('/settings/versions', [AmlaFoundationController::class, 'settingsVersions']);
    Route::get('/calendar', [AmlaFoundationController::class, 'calendar']);
    Route::get('/deadlines/preview', [AmlaFoundationController::class, 'deadlinePreview']);

    Route::get('/change-requests', [AmlaChangeRequestController::class, 'index']);
    Route::post('/change-requests', [AmlaChangeRequestController::class, 'store']);
    Route::get('/change-requests/{changeRequest}', [AmlaChangeRequestController::class, 'show'])->whereNumber('changeRequest');
    Route::post('/change-requests/{changeRequest}/approve', [AmlaChangeRequestController::class, 'approve'])->whereNumber('changeRequest');
    Route::post('/change-requests/{changeRequest}/reject', [AmlaChangeRequestController::class, 'reject'])->whereNumber('changeRequest');
    Route::post('/change-requests/{changeRequest}/withdraw', [AmlaChangeRequestController::class, 'withdraw'])->whereNumber('changeRequest');

    Route::get('/log', [AmlaLogController::class, 'index']);
});
