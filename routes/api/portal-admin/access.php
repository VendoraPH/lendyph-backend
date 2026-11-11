<?php

use App\Http\Controllers\Api\PortalAdmin\PortalAccessController;
use App\Http\Controllers\Api\PortalAdmin\PortalSettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Member Portal, staff side: settings, terms and members' access (slice P1)
|--------------------------------------------------------------------------
|
| Required from routes/api.php INSIDE the staff group, with the other
| routes/api/portal-admin/*.php files. Permissions are checked in the
| controllers (`member_portal:*`). `{borrower}` uses the branch-scoped
| binding, so another branch's member answers 404.
*/
Route::prefix('portal-admin')->name('portal-admin.')->group(function () {
    Route::get('/settings', [PortalSettingsController::class, 'show'])->name('settings.show');
    Route::put('/settings', [PortalSettingsController::class, 'update'])->name('settings.update');
    Route::post('/terms', [PortalSettingsController::class, 'publishTerms'])->name('terms.store');

    Route::get('/stats', [PortalAccessController::class, 'stats'])->name('stats');
    Route::get('/accounts', [PortalAccessController::class, 'accounts'])->name('accounts');

    Route::prefix('members/{borrower}')->whereNumber('borrower')->name('members.')->group(function () {
        Route::get('/access', [PortalAccessController::class, 'show'])->name('access.show');
        Route::put('/access', [PortalAccessController::class, 'updateAccess'])->name('access.update');
        // No route limiter: CodeDesk caps codes per member and per staff user
        // after the permission and branch checks.
        Route::post('/invite', [PortalAccessController::class, 'invite'])->name('invite');
        Route::post('/reset-code', [PortalAccessController::class, 'resetCode'])->name('reset-code');
        Route::post('/unlock', [PortalAccessController::class, 'unlock'])->name('unlock');
        Route::post('/sign-out', [PortalAccessController::class, 'signOut'])->name('sign-out');
        Route::get('/communications', [PortalAccessController::class, 'communications'])->name('communications');
    });
});
