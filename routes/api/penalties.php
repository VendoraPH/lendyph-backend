<?php

use App\Http\Controllers\Api\PenaltyConfigController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Penalty configurations (Phase 4, Settings → Penalties)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated group. Permissions
| are checked in the controller and form requests (`penalties:view`,
| `create`, `update`, `delete`). Every id is a number.
*/
Route::prefix('penalty-configs')->group(function () {
    Route::get('/', [PenaltyConfigController::class, 'index']);
    Route::post('/', [PenaltyConfigController::class, 'store']);
    Route::get('/{penaltyConfig}', [PenaltyConfigController::class, 'show'])->whereNumber('penaltyConfig');
    Route::put('/{penaltyConfig}', [PenaltyConfigController::class, 'update'])->whereNumber('penaltyConfig');
    Route::patch('/{penaltyConfig}/status', [PenaltyConfigController::class, 'updateStatus'])->whereNumber('penaltyConfig');
    Route::delete('/{penaltyConfig}', [PenaltyConfigController::class, 'destroy'])->whereNumber('penaltyConfig');
});
