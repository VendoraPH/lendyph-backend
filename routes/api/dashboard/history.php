<?php

use App\Http\Controllers\Api\Dashboard\DashboardHistoryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Executive dashboard: portfolio history (Phase 6 EB-B)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated group. Month-end
| points or one date from the daily snapshots; `dashboard:analytics` plus
| `reports:view` (DashboardHistoryRequest).
*/
Route::get('/dashboard/history', [DashboardHistoryController::class, 'index'])->name('dashboard.history');
