<?php

use App\Http\Controllers\Api\LoanOrigination\OriginationHandOffController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Loan origination, staff side: the hand-off (LO-B4)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated staff group.
| Permissions are checked by the service, in words
| (`loan_origination:convert` and `loans:create`, plus `borrowers:create` for
| a new member), never by route middleware. `{application}` is branch-scoped
| by its binding. Linking a member before it is LO-B3's
| (routes/api/loan-origination/staff.php).
*/
Route::prefix('loan-origination')->group(function () {
    Route::post('/applications/{application}/submit-for-approval', [OriginationHandOffController::class, 'store'])->whereNumber('application');
});
