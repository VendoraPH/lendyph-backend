<?php

use App\Http\Controllers\Api\Amla\AmlaAlertWorkflowController;
use App\Http\Controllers\Api\Amla\AmlaCaseController;
use App\Http\Controllers\Api\Amla\AmlaFilingController;
use App\Http\Controllers\Api\Amla\AmlaInboxController;
use App\Http\Controllers\Api\Amla\AmlaRiskDowngradeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AMLA cases and filings (Phase 6, AMLA-B6)
|--------------------------------------------------------------------------
|
| Loaded by routes/api.php inside the authenticated staff group. Every route
| is named `amla.*` and passes the `amla` limiter. Each checks its own key
| before any record is looked up, so ids are plain `{id}`, bounded to 18
| digits, never bound to a model, and a record outside the caller's scope
| answers 404. Cases and STRs also need head-office access.
*/
Route::prefix('amla')->name('amla.')->middleware('throttle:amla')->group(function () {
    $id = '[0-9]{1,18}';

    Route::post('/alerts/{id}/assign', [AmlaAlertWorkflowController::class, 'assign'])->where('id', $id)->name('alerts.assign');
    Route::post('/alerts/{id}/review', [AmlaAlertWorkflowController::class, 'review'])->where('id', $id)->name('alerts.review');
    Route::post('/alerts/{id}/escalate', [AmlaAlertWorkflowController::class, 'escalate'])->where('id', $id)->name('alerts.escalate');
    Route::post('/alerts/{id}/close', [AmlaAlertWorkflowController::class, 'close'])->where('id', $id)->name('alerts.close');
    Route::get('/alerts/{id}/history', [AmlaAlertWorkflowController::class, 'history'])->where('id', $id)->name('alerts.history');

    Route::get('/cases', [AmlaCaseController::class, 'index'])->name('cases.index');
    Route::post('/cases', [AmlaCaseController::class, 'store'])->name('cases.store');
    Route::get('/cases/{id}', [AmlaCaseController::class, 'show'])->where('id', $id)->name('cases.show');
    Route::post('/cases/{id}/assign', [AmlaCaseController::class, 'assign'])->where('id', $id)->name('cases.assign');
    Route::post('/cases/{id}/notes', [AmlaCaseController::class, 'note'])->where('id', $id)->name('cases.notes');
    Route::post('/cases/{id}/evidence', [AmlaCaseController::class, 'evidence'])->where('id', $id)->name('cases.evidence.store');
    Route::get('/cases/{id}/evidence/{evidenceId}', [AmlaCaseController::class, 'download'])->where(['id' => $id, 'evidenceId' => $id])->name('cases.evidence.show');
    Route::post('/cases/{id}/decision', [AmlaCaseController::class, 'decide'])->where('id', $id)->name('cases.decision');
    Route::post('/cases/{id}/str', [AmlaCaseController::class, 'str'])->where('id', $id)->name('cases.str');

    Route::post('/ctr-candidates/{id}/report', [AmlaFilingController::class, 'prepareCtr'])->where('id', $id)->name('ctr-candidates.report');
    Route::post('/ctr-candidates/{id}/not-reportable', [AmlaFilingController::class, 'notReportable'])->where('id', $id)->name('ctr-candidates.not-reportable');
    Route::get('/reports', [AmlaFilingController::class, 'index'])->name('reports.index');
    Route::get('/reports/{id}', [AmlaFilingController::class, 'show'])->where('id', $id)->name('reports.show');
    Route::post('/reports/{id}/approve', [AmlaFilingController::class, 'approve'])->where('id', $id)->name('reports.approve');
    Route::post('/reports/{id}/return', [AmlaFilingController::class, 'return'])->where('id', $id)->name('reports.return');
    Route::post('/reports/{id}/submissions', [AmlaFilingController::class, 'submission'])->where('id', $id)->name('reports.submissions');
    Route::get('/reports/{id}/export', [AmlaFilingController::class, 'export'])->where('id', $id)->name('reports.export');
    Route::get('/report-formats', [AmlaFilingController::class, 'formats'])->name('report-formats');
    Route::get('/filing-deadlines', [AmlaFilingController::class, 'deadlines'])->name('filing-deadlines');

    Route::get('/inbox', [AmlaInboxController::class, 'index'])->name('inbox.index');
    Route::post('/inbox/{id}/read', [AmlaInboxController::class, 'read'])->where('id', $id)->name('inbox.read');

    Route::get('/risk-downgrades', [AmlaRiskDowngradeController::class, 'index'])->name('risk-downgrades.index');
    Route::post('/risk-downgrades/{id}/approve', [AmlaRiskDowngradeController::class, 'approve'])->where('id', $id)->name('risk-downgrades.approve');
    Route::post('/risk-downgrades/{id}/reject', [AmlaRiskDowngradeController::class, 'reject'])->where('id', $id)->name('risk-downgrades.reject');
});
