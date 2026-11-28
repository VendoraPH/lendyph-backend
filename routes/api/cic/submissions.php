<?php

use App\Http\Controllers\Api\Cic\CicSubmissionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CIC reporting: files, encryption and submissions (slice C)
|--------------------------------------------------------------------------
|
| Generate a CSDF file from a complete capture, review it, encrypt it to
| CIC's active key (only the .zip.gpg is kept), download it, mark it
| submitted and record CIC's answer. Each action checks its own cic:*
| permission plus branches:view_all in the controller or its form request.
*/
Route::prefix('cic')->group(function () {
    Route::get('/submissions', [CicSubmissionController::class, 'index'])->middleware('throttle:cic');
    Route::post('/submissions', [CicSubmissionController::class, 'store']);
    Route::get('/submissions/{id}', [CicSubmissionController::class, 'show'])->whereNumber('id')->middleware('throttle:cic');
    Route::post('/submissions/{id}/encrypt', [CicSubmissionController::class, 'encrypt'])->whereNumber('id');
    Route::get('/submissions/{id}/download', [CicSubmissionController::class, 'download'])->whereNumber('id');
    Route::post('/submissions/{id}/mark-submitted', [CicSubmissionController::class, 'markSubmitted'])->whereNumber('id');
    Route::post('/submissions/{id}/acknowledgement', [CicSubmissionController::class, 'acknowledge'])->whereNumber('id');
});
