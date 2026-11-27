<?php

use App\Http\Controllers\Api\PortalAdmin\PortalRequestQueueController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Member Portal, staff side: the requests queue (slice P3)
|--------------------------------------------------------------------------
|
| Required from routes/api.php INSIDE the staff group. Permissions are checked
| in the controller (`member_portal:requests` plus each type's own). `{request}`
| is looked up within the caller's branches, so another branch's answers 404.
*/
Route::prefix('portal-admin/requests')->name('portal-admin.requests.')->group(function () {
    Route::get('/', [PortalRequestQueueController::class, 'index'])->name('index');
    Route::get('/{request}', [PortalRequestQueueController::class, 'show'])->whereNumber('request')->name('show');
    Route::post('/{request}/decision', [PortalRequestQueueController::class, 'decide'])->whereNumber('request')->name('decision');
});
