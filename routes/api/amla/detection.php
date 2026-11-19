<?php

use App\Http\Controllers\Api\Amla\AmlaDetectionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AMLA detection (Phase 6, AMLA-B3)
|--------------------------------------------------------------------------
|
| Loaded by routes/api.php inside the authenticated staff group. Every route
| is named `amla.*` and passes the `amla` limiter. Each checks its own key
| (`amla:alerts`, `amla:ctr`, or an AMLA view for the rules) before any
| record is looked up, so ids are plain `{id}`, bounded to 18 digits, never
| bound to a model. Rule changes go through POST /amla/change-requests.
*/
Route::prefix('amla')->name('amla.')->middleware('throttle:amla')->group(function () {
    Route::get('/alerts', [AmlaDetectionController::class, 'alerts'])->name('alerts.index');
    Route::get('/alerts/{id}', [AmlaDetectionController::class, 'alert'])->where('id', '[0-9]{1,18}')->name('alerts.show');
    Route::get('/ctr-candidates', [AmlaDetectionController::class, 'ctrCandidates'])->name('ctr-candidates.index');
    Route::get('/ctr-candidates/{id}', [AmlaDetectionController::class, 'ctrCandidate'])->where('id', '[0-9]{1,18}')->name('ctr-candidates.show');
    Route::get('/detection-rules', [AmlaDetectionController::class, 'rules'])->name('detection-rules.show');
    Route::get('/detection-rules/versions', [AmlaDetectionController::class, 'ruleVersions'])->name('detection-rules.versions');
});
