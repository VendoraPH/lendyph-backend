<?php

use App\Http\Controllers\Api\Amla\AmlaComplianceReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AMLA reports, exports and dashboard counts (Phase 6, AMLA-B7)
|--------------------------------------------------------------------------
|
| Loaded by routes/api.php inside the authenticated staff group. Every route
| is named `amla.*` and passes the `amla` limiter. Each checks its keys
| before anything is built or looked up. An export downloads through a
| signed link (SignedFileLink) that the controller re-checks against the
| signed-in user it was made for; ids are bounded to 18 digits.
*/
Route::prefix('amla')->name('amla.')->middleware('throttle:amla')->group(function () {
    Route::get('/compliance-reports', [AmlaComplianceReportController::class, 'index'])->name('compliance-reports.index');
    Route::get('/compliance-reports/{report}', [AmlaComplianceReportController::class, 'show'])->where('report', '[a-z_]{3,40}')->name('compliance-reports.show');
    Route::post('/compliance-reports/{report}/exports', [AmlaComplianceReportController::class, 'export'])->where('report', '[a-z_]{3,40}')->name('compliance-reports.export');
    Route::get('/exports/{id}/download', [AmlaComplianceReportController::class, 'download'])->where('id', '[0-9]{1,18}')->middleware('signed')->name('exports.download');
    Route::get('/dashboard/counts', [AmlaComplianceReportController::class, 'counts'])->name('dashboard.counts');
});
