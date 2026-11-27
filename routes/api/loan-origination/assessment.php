<?php

use App\Http\Controllers\Api\LoanOrigination\OriginationAssessmentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Loan origination, staff side: capacity assessments (slice LO-B4)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated staff group.
| Permissions are checked in the controller and form requests
| (`loan_origination:view` to read, `loan_origination:assess` to run and to
| save notes), never by route middleware. `{application}` is branch-scoped by
| its binding, so another branch's answers 404.
*/
Route::prefix('loan-origination')->group(function () {
    Route::get('/assessments', [OriginationAssessmentController::class, 'index']);

    Route::get('/applications/{application}/assessment', [OriginationAssessmentController::class, 'show'])->whereNumber('application');
    Route::patch('/applications/{application}/assessment', [OriginationAssessmentController::class, 'update'])->whereNumber('application');
    Route::post('/applications/{application}/assessment/rerun', [OriginationAssessmentController::class, 'rerun'])->whereNumber('application');
    Route::get('/applications/{application}/assessment/history', [OriginationAssessmentController::class, 'history'])->whereNumber('application');
    Route::get('/applications/{application}/assessment/pdf', [OriginationAssessmentController::class, 'pdf'])->whereNumber('application');
});
