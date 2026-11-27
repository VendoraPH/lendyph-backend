<?php

use App\Http\Controllers\Api\Portal\PortalDocumentController;
use App\Http\Controllers\Api\Portal\PortalHomeController;
use App\Http\Controllers\Api\Portal\PortalLoanController;
use App\Http\Controllers\Api\Portal\PortalNotificationController;
use App\Http\Controllers\Api\Portal\PortalSavingsController;
use App\Http\Controllers\Api\Portal\PortalShareCapitalController;
use App\Http\Middleware\Portal\PortalStack;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Member Portal: what a member can see (slice P2)
|--------------------------------------------------------------------------
|
| Required from routes/api.php OUTSIDE the staff group. Every route is
| signed in (PortalStack::signedIn()), takes the member only from the token,
| and finds `{id}` within the member's own records before it reads anything
| else, so another member's id answers 404. A part of the portal the
| cooperative switched off answers 403 (`portal.feature:<key>`); PDFs also
| take the portal-pdf limiter. Route names are `portal.<group>.<action>`.
*/
Route::prefix('portal')->name('portal.')->middleware(PortalStack::signedIn())->group(function () {
    Route::get('/dashboard', [PortalHomeController::class, 'dashboard'])->name('dashboard');
    Route::get('/membership', [PortalHomeController::class, 'membership'])->name('membership');
    Route::get('/profile', [PortalHomeController::class, 'profile'])->name('profile');

    Route::middleware('portal.feature:loans')->group(function () {
        Route::prefix('loans')->name('loans.')->group(function () {
            Route::get('/', [PortalLoanController::class, 'index'])->name('index');
            Route::get('/{id}', [PortalLoanController::class, 'show'])->whereNumber('id')->name('show');
            Route::get('/{id}/schedule', [PortalLoanController::class, 'schedule'])->whereNumber('id')->name('schedule');
            Route::get('/{id}/payments', [PortalLoanController::class, 'payments'])->whereNumber('id')->name('payments');
            Route::get('/{id}/statement', [PortalLoanController::class, 'statement'])
                ->whereNumber('id')->middleware(['portal.feature:statements', 'throttle:portal-pdf'])->name('statement');
        });

        Route::get('/payments', [PortalLoanController::class, 'allPayments'])->name('payments.index');
    });

    Route::prefix('share-capital')->name('share-capital.')->middleware('portal.feature:share_capital')->group(function () {
        Route::get('/', [PortalShareCapitalController::class, 'show'])->name('show');
        Route::get('/transactions', [PortalShareCapitalController::class, 'transactions'])->name('transactions');
        Route::middleware(['portal.feature:statements', 'throttle:portal-pdf'])->group(function () {
            Route::get('/statement', [PortalShareCapitalController::class, 'statement'])->name('statement');
            Route::get('/certificate', [PortalShareCapitalController::class, 'certificate'])->name('certificate');
        });
    });

    Route::prefix('savings')->name('savings.')->middleware('portal.feature:savings')->group(function () {
        Route::get('/', [PortalSavingsController::class, 'index'])->name('index');
        Route::get('/{id}', [PortalSavingsController::class, 'show'])->whereNumber('id')->name('show');
        Route::get('/{id}/transactions', [PortalSavingsController::class, 'transactions'])->whereNumber('id')->name('transactions');
        Route::get('/{id}/statement', [PortalSavingsController::class, 'statement'])
            ->whereNumber('id')->middleware(['portal.feature:statements', 'throttle:portal-pdf'])->name('statement');
    });

    Route::prefix('time-deposits')->name('time-deposits.')->middleware('portal.feature:time_deposits')->group(function () {
        Route::get('/', [PortalSavingsController::class, 'timeDeposits'])->name('index');
        Route::get('/{id}', [PortalSavingsController::class, 'timeDeposit'])->whereNumber('id')->name('show');
        Route::get('/{id}/certificate', [PortalSavingsController::class, 'certificate'])
            ->whereNumber('id')->middleware(['portal.feature:statements', 'throttle:portal-pdf'])->name('certificate');
    });

    Route::prefix('documents')->name('documents.')->middleware('portal.feature:documents')->group(function () {
        Route::get('/', [PortalDocumentController::class, 'index'])->name('index');
        Route::get('/{id}/download', [PortalDocumentController::class, 'download'])->whereNumber('id')->name('download');
    });

    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [PortalNotificationController::class, 'index'])->name('index');
        Route::get('/unread-count', [PortalNotificationController::class, 'unreadCount'])->name('unread-count');
        Route::post('/read-all', [PortalNotificationController::class, 'readAll'])->name('read-all');
        Route::post('/{id}/read', [PortalNotificationController::class, 'read'])->where('id', '[A-Za-z0-9-]+')->name('read');
    });
});
