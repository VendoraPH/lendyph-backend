<?php

use App\Http\Controllers\Api\Cda\CdaDashboardController;
use App\Http\Controllers\Api\Cda\CdaReportController;
use App\Http\Controllers\Api\Cda\CdaReportWorkflowController;
use App\Http\Controllers\Api\Cda\CdaSnapshotController;
use App\Http\Controllers\Api\Cda\CdaSubmissionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CDA Reports: reports, workflow, snapshots, submissions, dashboard and
| calendar (slice C2, design §3–§5)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated group, with the other
| routes/api/cda/*.php files. Every route needs `branches:view_all` plus its
| own `cda:*` permission, and a restricted report its view permission
| (CdaAccess, checked in the controllers and form requests).
*/
Route::prefix('cda')->group(function () {
    Route::get('/periods/{year}/dashboard', [CdaDashboardController::class, 'dashboard'])->whereNumber('year');
    Route::get('/periods/{year}/calendar', [CdaDashboardController::class, 'calendar'])->whereNumber('year');
    Route::get('/periods/{year}/submissions', [CdaSubmissionController::class, 'index'])->whereNumber('year');
    Route::get('/periods/{year}/reports/{type}', [CdaReportController::class, 'showByType'])->whereNumber('year')->where('type', '[a-z_]+');

    Route::get('/reports/{report}', [CdaReportController::class, 'show'])->whereNumber('report');
    Route::put('/reports/{report}', [CdaReportController::class, 'update'])->whereNumber('report');
    Route::post('/reports/{report}/workflow', [CdaReportWorkflowController::class, 'store'])->whereNumber('report');
    Route::get('/reports/{report}/snapshots', [CdaSnapshotController::class, 'index'])->whereNumber('report');
    Route::get('/reports/{report}/snapshots/{version}', [CdaSnapshotController::class, 'show'])->whereNumber(['report', 'version']);
    Route::post('/reports/{report}/snapshots/{version}/verify', [CdaSnapshotController::class, 'verify'])->whereNumber(['report', 'version']);

    Route::put('/submissions/{submission}', [CdaSubmissionController::class, 'update'])->whereNumber('submission');
});
