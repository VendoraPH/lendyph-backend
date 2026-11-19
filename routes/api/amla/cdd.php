<?php

use App\Http\Controllers\Api\Amla\AmlaCddController;
use App\Http\Controllers\Api\Amla\AmlaRiskRuleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AMLA customer due diligence and risk (Phase 6, AMLA-B4)
|--------------------------------------------------------------------------
|
| Loaded by routes/api.php inside the authenticated staff group, after
| foundation.php. Every route is named `amla.*` and passes the `amla`
| limiter. Each checks `amla:cdd` (the risk rules: head-office access too)
| before any record is looked up, so ids are plain `{id}`, bounded to 18
| digits, never bound to a model. `{subjectType}` is members or walk-ins.
*/
Route::prefix('amla')->name('amla.')->middleware('throttle:amla')->group(function () {
    Route::get('/cdd/subjects', [AmlaCddController::class, 'index'])->name('cdd.subjects');

    Route::prefix('/cdd/{subjectType}/{id}')
        ->where(['subjectType' => 'members|walk-ins', 'id' => '[0-9]{1,18}'])
        ->name('cdd.')
        ->group(function () {
            Route::get('/', [AmlaCddController::class, 'show'])->name('show');
            Route::put('/', [AmlaCddController::class, 'update'])->name('update');
            Route::post('/id-verifications', [AmlaCddController::class, 'storeIdVerification'])->name('id-verifications.store');
            Route::post('/risk-assessments', [AmlaCddController::class, 'storeRiskAssessment'])->name('risk-assessments.store');
            Route::post('/reviews', [AmlaCddController::class, 'storeReview'])->name('reviews.store');
        });

    Route::get('/risk-rules', [AmlaRiskRuleController::class, 'show'])->name('risk-rules.show');
});
