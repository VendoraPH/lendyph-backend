<?php

use App\Http\Controllers\Api\Dashboard\DashboardSettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Executive dashboard: alert thresholds (Phase 6 EB-C)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated group, with the other
| routes/api/dashboard/*.php files. One company-wide row: reading needs
| `dashboard:analytics`, changing needs `dashboard:settings` and
| `branches:view_all`, both checked in the controller and form request.
*/
Route::prefix('dashboard')->group(function () {
    Route::get('/settings', [DashboardSettingsController::class, 'show']);
    Route::put('/settings', [DashboardSettingsController::class, 'update']);
});
