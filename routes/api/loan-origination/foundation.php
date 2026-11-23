<?php

use App\Http\Controllers\Api\LoanOrigination\OriginationLinkController;
use App\Http\Controllers\Api\LoanOrigination\OriginationNoticeController;
use App\Http\Controllers\Api\LoanOrigination\OriginationSettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Loan origination, staff side: settings, notices and links (slice LO-B1)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated staff group, with the
| other routes/api/loan-origination/*.php files. Permissions are checked in
| the controllers and form requests (`loan_origination:view|settings`, plus
| `branches:view_all` for company-wide changes), never by route middleware.
| Notices are append-only: no route edits or removes one. These work while
| LOAN_ORIGINATION_ENABLED is off, so staff can prepare before the switch.
*/
Route::prefix('loan-origination')->group(function () {
    Route::get('/settings', [OriginationSettingsController::class, 'show']);
    Route::put('/settings', [OriginationSettingsController::class, 'update']);

    Route::get('/notices', [OriginationNoticeController::class, 'index']);
    Route::post('/notices', [OriginationNoticeController::class, 'store']);
    Route::get('/notices/{notice}', [OriginationNoticeController::class, 'show'])->whereNumber('notice');

    Route::get('/links', [OriginationLinkController::class, 'index']);
    Route::post('/links', [OriginationLinkController::class, 'store']);
    Route::get('/links/{link}', [OriginationLinkController::class, 'show'])->whereNumber('link');
    Route::patch('/links/{link}', [OriginationLinkController::class, 'update'])->whereNumber('link');
});
