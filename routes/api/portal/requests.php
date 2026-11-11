<?php

use App\Http\Controllers\Api\Portal\PortalRequestController;
use App\Http\Middleware\Portal\PortalStack;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Member Portal: the requests center (slice P3)
|--------------------------------------------------------------------------
|
| Required from routes/api.php OUTSIDE the staff group (portal design §3, §6).
| Each request type checks its own switch when sent. `{id}` is looked up
| within the signed-in member's own requests: another member's answers 404.
*/
Route::prefix('portal/requests')->name('portal.requests.')->middleware(PortalStack::signedIn())->group(function () {
    Route::get('/', [PortalRequestController::class, 'index'])->name('index');
    Route::get('/options', [PortalRequestController::class, 'options'])->name('options');
    Route::post('/', [PortalRequestController::class, 'store'])->middleware('throttle:portal-writes')->name('store');
    Route::get('/{id}', [PortalRequestController::class, 'show'])->whereNumber('id')->name('show');
    Route::post('/{id}/cancel', [PortalRequestController::class, 'cancel'])->whereNumber('id')->middleware('throttle:portal-writes')->name('cancel');
    Route::post('/{id}/reply', [PortalRequestController::class, 'reply'])->whereNumber('id')->middleware('throttle:portal-writes')->name('reply');
});
