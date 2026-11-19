<?php

use App\Http\Controllers\Api\LoanOrigination\OriginationHandOffController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Loan origination, staff side: linking a member and the hand-off (LO-B4)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated staff group.
| Permissions are checked by the services, in words
| (`loan_origination:convert` and `loans:create`, plus `borrowers:create` for
| a new member; `loan_origination:update` and `borrowers:view` to link),
| never by route middleware. `{application}` is branch-scoped by its binding.
*/
Route::prefix('loan-origination')->group(function () {
    Route::post('/applications/{application}/link-member', [OriginationHandOffController::class, 'linkMember'])->whereNumber('application');
    Route::post('/applications/{application}/submit-for-approval', [OriginationHandOffController::class, 'store'])->whereNumber('application');
});
