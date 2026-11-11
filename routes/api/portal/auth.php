<?php

use App\Http\Controllers\Api\Portal\PortalAuthController;
use App\Http\Controllers\Api\Portal\PortalPublicController;
use App\Http\Controllers\Api\Portal\PortalSecurityController;
use App\Http\Middleware\Portal\PortalNoStore;
use App\Http\Middleware\Portal\PortalStack;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Member Portal: sign-in, codes, the account and its security (slice P1)
|--------------------------------------------------------------------------
|
| Required from routes/api.php OUTSIDE the staff group, with the other
| routes/api/portal/*.php files: a staff token is never accepted here and a
| portal token never there. Route names are `portal.<group>.<action>`; the
| route sweeps read them. No route takes a member id or binds a model: the
| member comes from the token (App\Services\Portal\MemberScope), and `{id}`
| is a plain number looked up within the member's own records.
*/
Route::prefix('portal')->name('portal.')->group(function () {
    // Public. `config` answers while the portal is off; sign-in checks the
    // switch itself so a refused attempt is in the member's history.
    Route::get('/config', [PortalPublicController::class, 'config'])
        ->middleware([PortalNoStore::class, 'throttle:portal-public'])->name('config');

    Route::middleware(PortalStack::public())->group(function () {
        Route::get('/terms', [PortalPublicController::class, 'terms'])->middleware('throttle:portal-public')->name('terms');

        Route::middleware('throttle:portal-codes')->prefix('auth/codes')->name('auth.codes.')->group(function () {
            Route::post('/verify', [PortalAuthController::class, 'verifyCode'])->name('verify');
            Route::post('/otp', [PortalAuthController::class, 'verifyOtp'])->name('otp');
            Route::post('/complete', [PortalAuthController::class, 'completeCode'])->name('complete');
        });
    });

    Route::post('/auth/login', [PortalAuthController::class, 'login'])
        ->middleware([PortalNoStore::class, 'throttle:portal-login'])->name('auth.login');

    // Signed in.
    Route::middleware(PortalStack::signedIn())->group(function () {
        Route::prefix('auth')->name('auth.')->group(function () {
            Route::post('/logout', [PortalAuthController::class, 'logout'])->name('logout');
            Route::get('/me', [PortalAuthController::class, 'me'])->name('me');
            Route::post('/terms/accept', [PortalAuthController::class, 'acceptTerms'])->middleware('throttle:portal-writes')->name('terms');
            Route::post('/password/change', [PortalAuthController::class, 'changePassword'])->middleware('throttle:portal-security')->name('password');
        });

        Route::prefix('security')->group(function () {
            Route::get('/sessions', [PortalSecurityController::class, 'sessions'])->name('sessions.index');
            Route::get('/logins', [PortalSecurityController::class, 'logins'])->name('logins.index');

            // Security actions: their own per-minute limit, no daily cap.
            Route::middleware('throttle:portal-security')->group(function () {
                Route::post('/sessions/logout-others', [PortalSecurityController::class, 'logoutOthers'])->name('sessions.logout-others');
                Route::delete('/sessions/{id}', [PortalSecurityController::class, 'destroySession'])->whereNumber('id')->name('sessions.destroy');
                Route::post('/logins/{id}/report', [PortalSecurityController::class, 'report'])->whereNumber('id')->name('logins.report');
            });
        });
    });
});
