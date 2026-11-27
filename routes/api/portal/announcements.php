<?php

use App\Http\Controllers\Api\Portal\PortalAnnouncementController;
use App\Http\Middleware\Portal\PortalStack;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Member Portal: announcements (slice P3)
|--------------------------------------------------------------------------
|
| Required from routes/api.php OUTSIDE the staff group (portal design §3, §7).
| `{id}` is looked up within what the member's audience may see: anything
| else answers 404.
*/
Route::prefix('portal/announcements')->name('portal.announcements.')
    ->middleware([...PortalStack::signedIn(), 'portal.feature:announcements'])
    ->group(function () {
        Route::get('/', [PortalAnnouncementController::class, 'index'])->name('index');
        Route::get('/{id}', [PortalAnnouncementController::class, 'show'])->whereNumber('id')->name('show');
        Route::post('/{id}/read', [PortalAnnouncementController::class, 'read'])->whereNumber('id')->name('read');
    });
