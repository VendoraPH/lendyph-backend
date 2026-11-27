<?php

use App\Http\Controllers\Api\Cda\CdaComplianceRequirementController;
use App\Http\Controllers\Api\Cda\CdaDocumentController;
use App\Http\Controllers\Api\Cda\CdaMediationCaseController;
use App\Http\Controllers\Api\Cda\CdaOfficerController;
use App\Http\Controllers\Api\Cda\CdaSocialProgramController;
use App\Http\Controllers\Api\Cda\CdaSubmissionProofController;
use App\Http\Controllers\Api\Cda\CdaTrainingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CDA Reports: registers and the document repository (slice C3)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated group, with the other
| routes/api/cda/*.php files. Every route needs `branches:view_all` plus its
| own `cda:*` permission (CdaAccess, checked in the controllers and form
| requests); mediation needs `cda:view_mediation` / `cda:manage_mediation`,
| and restricted documents their view permission. Ids are numeric.
|
| Parameter names never reuse a branch-owned record's name (`document`,
| `borrower`, `loan`, `user`, …): BranchAccessRouteSweepTest binds those to
| the member models. A CDA document is `{cdaDocument}`; it belongs to no
| branch (CDA access needs branches:view_all).
*/
Route::prefix('cda')->group(function () {
    Route::get('/officers', [CdaOfficerController::class, 'index']);
    Route::post('/officers', [CdaOfficerController::class, 'store']);
    Route::put('/officers/{officer}', [CdaOfficerController::class, 'update'])->whereNumber('officer');
    Route::delete('/officers/{officer}', [CdaOfficerController::class, 'destroy'])->whereNumber('officer');

    Route::get('/trainings', [CdaTrainingController::class, 'index']);
    Route::post('/trainings', [CdaTrainingController::class, 'store']);
    Route::put('/trainings/{training}', [CdaTrainingController::class, 'update'])->whereNumber('training');
    Route::delete('/trainings/{training}', [CdaTrainingController::class, 'destroy'])->whereNumber('training');

    Route::get('/periods/{year}/social-programs', [CdaSocialProgramController::class, 'index'])->whereNumber('year');
    Route::post('/periods/{year}/social-programs', [CdaSocialProgramController::class, 'store'])->whereNumber('year');
    Route::put('/social-programs/{program}', [CdaSocialProgramController::class, 'update'])->whereNumber('program');
    Route::delete('/social-programs/{program}', [CdaSocialProgramController::class, 'destroy'])->whereNumber('program');

    Route::get('/periods/{year}/mediation-cases', [CdaMediationCaseController::class, 'index'])->whereNumber('year');
    Route::post('/periods/{year}/mediation-cases', [CdaMediationCaseController::class, 'store'])->whereNumber('year');
    Route::put('/mediation-cases/{case}', [CdaMediationCaseController::class, 'update'])->whereNumber('case');
    Route::delete('/mediation-cases/{case}', [CdaMediationCaseController::class, 'destroy'])->whereNumber('case');

    Route::get('/periods/{year}/documents', [CdaDocumentController::class, 'index'])->whereNumber('year');
    Route::post('/periods/{year}/documents', [CdaDocumentController::class, 'store'])->whereNumber('year');
    Route::get('/documents/{cdaDocument}/versions', [CdaDocumentController::class, 'versions'])->whereNumber('cdaDocument');
    Route::post('/documents/{cdaDocument}/versions', [CdaDocumentController::class, 'addVersion'])->whereNumber('cdaDocument');
    Route::post('/documents/{cdaDocument}/withdraw', [CdaDocumentController::class, 'withdraw'])->whereNumber('cdaDocument');

    Route::post('/submissions/{submission}/proof', [CdaSubmissionProofController::class, 'store'])->whereNumber('submission');

    Route::get('/periods/{year}/requirements', [CdaComplianceRequirementController::class, 'index'])->whereNumber('year');
    Route::post('/periods/{year}/requirements', [CdaComplianceRequirementController::class, 'store'])->whereNumber('year');
    Route::put('/requirements/{requirement}', [CdaComplianceRequirementController::class, 'update'])->whereNumber('requirement');
    Route::delete('/requirements/{requirement}', [CdaComplianceRequirementController::class, 'destroy'])->whereNumber('requirement');
});
