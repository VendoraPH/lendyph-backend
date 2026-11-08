<?php

use App\Http\Controllers\Api\CreditScoringController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Credit Scoring — placeholders
|--------------------------------------------------------------------------
|
| Every endpoint the frontend's Credit Scoring module calls, answering 501
| to a caller who holds the permission and 403 to one who does not. The
| frontend shows "not connected yet" on 404 and 501 only, so 501 is what a
| permitted user must get until the module is built. Permissions are checked
| in the controller, like every other API endpoint.
|
| Required from routes/api.php inside the authenticated group, at the place
| these routes sat before they moved here, so their order is unchanged.
|
| `{borrowerId}` is a plain number, not a bound Borrower: a placeholder
| reads nothing from the database. No `GET /alerts`; alerts arrive inside
| the risk-monitoring response (see the frontend's
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
});
