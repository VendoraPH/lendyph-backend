<?php

use App\Http\Controllers\Api\LoanOrigination\PublicApply\PublicApplyController;
use App\Http\Middleware\PublicApply\PublicApplyStack;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Loan origination: the public application (slice LO-B2)
|--------------------------------------------------------------------------
|
| Required from routes/api.php OUTSIDE the staff group, inside its forced
| `/public-apply` prefix and `public-apply.` name. No account and no staff
| token: a link token, and after the code step an `X-Application-Session`
| header. Each route carries PublicApplyStack with its own `public-apply-*`
| limiter (LoanOriginationServiceProvider). Nothing answers while
| LOAN_ORIGINATION_ENABLED is off.
*/
Route::get('/{token}', [PublicApplyController::class, 'portal'])
    ->middleware(PublicApplyStack::for('public-apply-read'))->name('portal');

Route::post('/{token}/otp', [PublicApplyController::class, 'requestCode'])
    ->middleware(PublicApplyStack::for('public-apply-codes'))->name('otp.request');

Route::post('/{token}/otp/verify', [PublicApplyController::class, 'verifyCode'])
    ->middleware(PublicApplyStack::for('public-apply-verify'))->name('otp.verify');

Route::get('/{token}/draft', [PublicApplyController::class, 'showDraft'])
    ->middleware(PublicApplyStack::for('public-apply-read', 'intake'))->name('draft.show');

Route::put('/{token}/draft', [PublicApplyController::class, 'saveDraft'])
    ->middleware(PublicApplyStack::for('public-apply-drafts', 'intake'))->name('draft.save');

Route::post('/{token}/estimate', [PublicApplyController::class, 'estimate'])
    ->middleware(PublicApplyStack::for('public-apply-estimate', 'intake'))->name('estimate');

Route::post('/{token}/documents', [PublicApplyController::class, 'uploadDocument'])
    ->middleware(PublicApplyStack::for('public-apply-uploads', 'any'))->name('documents.store');

Route::delete('/{token}/documents/{document}', [PublicApplyController::class, 'removeDocument'])
    ->middleware(PublicApplyStack::for('public-apply-uploads', 'intake'))->name('documents.destroy');

Route::post('/{token}/submit', [PublicApplyController::class, 'submit'])
    ->middleware(PublicApplyStack::for('public-apply-submit', 'intake'))->name('submit');

Route::post('/{token}/logout', [PublicApplyController::class, 'logout'])
    ->middleware(PublicApplyStack::for('public-apply-logout', 'any'))->name('logout');

Route::get('/{token}/track', [PublicApplyController::class, 'track'])
    ->middleware(PublicApplyStack::for('public-apply-read', 'track'))->name('track');
