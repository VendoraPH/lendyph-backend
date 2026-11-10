<?php

use App\Http\Controllers\Api\Cda\CdaPeriodController;
use App\Http\Controllers\Api\Cda\CdaRegulatoryProfileController;
use App\Http\Controllers\Api\Cda\CdaReportDeadlineController;
use App\Http\Controllers\Api\Cda\CdaSettingController;
use App\Http\Controllers\Api\Cda\CdaTemplateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CDA Reports: periods, deadlines, profile, settings and templates (slice C1)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated group, with the other
| routes/api/cda/*.php files. Every route needs `branches:view_all` plus its
| own `cda:*` permission (CdaAccess, checked in the controllers and form
| requests); template writes are super_admin's alone. Literal segments come
| before the {template} wildcard.
*/
Route::prefix('cda')->group(function () {
    Route::get('/periods', [CdaPeriodController::class, 'index']);
    Route::post('/periods', [CdaPeriodController::class, 'store']);
    Route::get('/periods/{year}', [CdaPeriodController::class, 'show'])->whereNumber('year');
    Route::patch('/periods/{year}', [CdaPeriodController::class, 'update'])->whereNumber('year');

    Route::patch('/reports/{report}/deadline', [CdaReportDeadlineController::class, 'update'])->whereNumber('report');

    Route::get('/regulatory-profile', [CdaRegulatoryProfileController::class, 'show']);
    Route::put('/regulatory-profile', [CdaRegulatoryProfileController::class, 'update']);

    Route::get('/settings', [CdaSettingController::class, 'show']);
    Route::put('/settings', [CdaSettingController::class, 'update']);

    Route::get('/templates', [CdaTemplateController::class, 'index']);
    Route::post('/templates', [CdaTemplateController::class, 'store']);
    Route::post('/templates/validate', [CdaTemplateController::class, 'validateDefinition']);
    Route::get('/templates/{template}', [CdaTemplateController::class, 'show'])->whereNumber('template');
    Route::put('/templates/{template}', [CdaTemplateController::class, 'update'])->whereNumber('template');
    Route::post('/templates/{template}/activate', [CdaTemplateController::class, 'activate'])->whereNumber('template');
    Route::post('/templates/{template}/retire', [CdaTemplateController::class, 'retire'])->whereNumber('template');
    Route::post('/templates/{template}/excel-file', [CdaTemplateController::class, 'excelFile'])->whereNumber('template');
});
