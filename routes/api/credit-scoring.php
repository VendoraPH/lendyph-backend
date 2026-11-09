<?php

use App\Http\Controllers\Api\CreditAssessmentController;
use App\Http\Controllers\Api\CreditScoringController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Credit Scoring
|--------------------------------------------------------------------------
|
| The endpoints the frontend's Credit Scoring module calls, plus a loan
| application's assessment. Permissions are checked in the controllers and
| requests, like every other API endpoint: view for the reads, settings for
| the scorecard and settings (both verbs), override for decisions, and the
| assessment also needs loans:view to read and loans:update to save.
|
| Required from routes/api.php inside the authenticated group, at the place
| these routes sat before they moved here, so their order is unchanged; the
| assessment routes come after them.
|
| `{borrowerId}` is a plain number, not a bound Borrower: the controller
| resolves it through Borrower::visibleTo(), so another branch's member
| answers 404. `{loan}` binds only loans the caller may see. No `GET /alerts`;
| alerts arrive inside the risk-monitoring response (see the frontend's
| docs/CREDIT_SCORING_BACKEND_HANDOFF.md).
*/
Route::prefix('credit-scoring')->group(function () {
    Route::get('/dashboard', [CreditScoringController::class, 'dashboard']);
    Route::get('/borrowers', [CreditScoringController::class, 'borrowers']);
    Route::get('/borrowers/{borrowerId}', [CreditScoringController::class, 'borrower'])
        ->whereNumber('borrowerId');
    Route::get('/borrowers/{borrowerId}/history', [CreditScoringController::class, 'borrowerHistory'])
        ->whereNumber('borrowerId');
    Route::get('/borrowers/{borrowerId}/policy-flags', [CreditScoringController::class, 'policyFlags'])
        ->whereNumber('borrowerId');
    Route::get('/score-history', [CreditScoringController::class, 'scoreHistory']);
    Route::get('/risk-monitoring', [CreditScoringController::class, 'riskMonitoring']);
    Route::get('/scorecard-config', [CreditScoringController::class, 'showScorecardConfig']);
    Route::put('/scorecard-config', [CreditScoringController::class, 'updateScorecardConfig']);
    Route::get('/settings', [CreditScoringController::class, 'showSettings']);
    Route::put('/settings', [CreditScoringController::class, 'updateSettings']);
    Route::post('/decisions', [CreditScoringController::class, 'storeDecision']);

    Route::get('/loans/{loan}/assessment', [CreditAssessmentController::class, 'show'])
        ->whereNumber('loan');
    Route::put('/loans/{loan}/assessment', [CreditAssessmentController::class, 'update'])
        ->whereNumber('loan');
});
