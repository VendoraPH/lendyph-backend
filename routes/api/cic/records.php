<?php

use App\Http\Controllers\Api\Cic\CicCapturesController;
use App\Http\Controllers\Api\Cic\CicIssuesController;
use App\Http\Controllers\Api\Cic\CicReadinessController;
use App\Http\Controllers\Api\Cic\CicRecordsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CIC reporting: records, validation and captures (records slice)
|--------------------------------------------------------------------------
|
| Readiness, records and issues are built live at `reference_date`, or read
| from a capture (`capture_id`). Each needs `cic:view` and `branches:view_all`
| (CicController); member names and field values also need `borrowers:view`.
| Taking a test or correction capture needs `cic:prepare`. Every route answers
| 404 while CIC is off or hidden in Menu Visibility.
*/
Route::prefix('cic')->group(function () {
    Route::get('/readiness', [CicReadinessController::class, 'show'])->middleware('throttle:cic');
    Route::get('/records', [CicRecordsController::class, 'index'])->middleware('throttle:cic');
    Route::get('/records/{type}/{key}', [CicRecordsController::class, 'show'])->middleware('throttle:cic')
        ->where(['type' => 'ID|CI|CN|NE', 'key' => '[^/]{1,38}']);
    Route::get('/issues', [CicIssuesController::class, 'index'])->middleware('throttle:cic');
    Route::get('/issues/export', [CicIssuesController::class, 'export'])->middleware('throttle:exports');
    Route::get('/captures', [CicCapturesController::class, 'index']);
    Route::post('/captures', [CicCapturesController::class, 'store']);
    Route::get('/captures/{id}', [CicCapturesController::class, 'show'])->whereNumber('id');
});
