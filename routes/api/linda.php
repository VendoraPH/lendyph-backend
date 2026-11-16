<?php

use App\Http\Controllers\Api\Linda\LindaController;
use App\Http\Controllers\Api\Linda\LindaSettingsController;
use App\Http\Middleware\EnsureLindaEnabled;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Linda, the staff assistant
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated group. Permissions
| are checked in the form requests and controllers (`linda:use`, and
| `linda:settings` with `branches:view_all`), never by route middleware.
| Chat answers 501 `linda_off` while the backend switch is off, and the
| `linda` limiter meters it; the settings work while Linda is off.
*/
Route::prefix('linda')->group(function () {
    Route::post('/chat', [LindaController::class, 'chat'])->middleware([EnsureLindaEnabled::class, 'throttle:linda']);
    Route::get('/settings', [LindaSettingsController::class, 'show']);
    Route::put('/settings', [LindaSettingsController::class, 'update']);
});
