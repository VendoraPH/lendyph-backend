<?php

use App\Http\Controllers\Api\PortalAdmin\PortalAnnouncementAdminController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Member Portal, staff side: announcements (slice P3)
|--------------------------------------------------------------------------
|
| Required from routes/api.php INSIDE the staff group. Permission checked in
| the controller (`member_portal:announcements`). `{announcement}` is looked
| up among the ones the caller manages: another branch's answers 404.
*/
Route::prefix('portal-admin/announcements')->name('portal-admin.announcements.')->group(function () {
    Route::get('/', [PortalAnnouncementAdminController::class, 'index'])->name('index');
    Route::post('/', [PortalAnnouncementAdminController::class, 'store'])->name('store');
    Route::put('/{announcement}', [PortalAnnouncementAdminController::class, 'update'])->whereNumber('announcement')->name('update');
    Route::delete('/{announcement}', [PortalAnnouncementAdminController::class, 'destroy'])->whereNumber('announcement')->name('destroy');
    Route::post('/{announcement}/publish', [PortalAnnouncementAdminController::class, 'publish'])->whereNumber('announcement')->name('publish');
    Route::post('/{announcement}/withdraw', [PortalAnnouncementAdminController::class, 'withdraw'])->whereNumber('announcement')->name('withdraw');
});
