<?php

use App\Http\Controllers\Api\LoanOrigination\Staff\ApplicationController;
use App\Http\Controllers\Api\LoanOrigination\Staff\DashboardController;
use App\Http\Controllers\Api\LoanOrigination\Staff\DocumentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Loan origination, staff side: processing (slice LO-B3)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated staff group, with the
| other routes/api/loan-origination/*.php files, on the group's `api`
| limiter. Permissions are checked in the controllers and form requests
| (`loan_origination:view` to read, `loan_origination:update` to work on an
| application, `loans:reject` to reject, `borrowers:view` for member
| matches), never by route middleware. Another branch's application or
| document answers 404. Messages to applicants are logged, never sent.
*/
Route::prefix('loan-origination')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'show']);

    Route::get('/applications', [ApplicationController::class, 'index']);
    Route::get('/applications/{application}', [ApplicationController::class, 'show'])->whereNumber('application');
    Route::post('/applications/{application}/transition', [ApplicationController::class, 'transition'])->whereNumber('application');
    Route::post('/applications/{application}/notes', [ApplicationController::class, 'addNote'])->whereNumber('application');
    Route::post('/applications/{application}/assign', [ApplicationController::class, 'assign'])->whereNumber('application');
    Route::post('/applications/{application}/request-information', [ApplicationController::class, 'requestInformation'])->whereNumber('application');
    Route::post('/applications/{application}/remind', [ApplicationController::class, 'remind'])->whereNumber('application');
    Route::get('/applications/{application}/member-matches', [ApplicationController::class, 'memberMatches'])->whereNumber('application');
    Route::post('/applications/{application}/link-member', [ApplicationController::class, 'linkMember'])->whereNumber('application');

    Route::get('/documents', [DocumentController::class, 'index']);
    Route::post('/documents/{document}/verify', [DocumentController::class, 'verify'])->where('document', '[0-9]{1,18}');
    Route::post('/documents/{document}/reject', [DocumentController::class, 'reject'])->where('document', '[0-9]{1,18}');
    Route::post('/documents/{document}/request-replacement', [DocumentController::class, 'requestReplacement'])->where('document', '[0-9]{1,18}');
    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->where('document', '[0-9]{1,18}');
});
