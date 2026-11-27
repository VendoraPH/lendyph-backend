<?php

use App\Http\Controllers\Api\Savings\SavingsDashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Savings & Deposits: the dashboard (gap round S5)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated group, with the other
| routes/api/savings/*.php files. `savings:view`, checked in the form request.
*/
Route::prefix('savings')->group(function () {
    Route::get('/dashboard', SavingsDashboardController::class);
});
