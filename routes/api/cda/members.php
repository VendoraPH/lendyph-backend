<?php

use App\Http\Controllers\Api\Cda\MembershipController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CDA Reports: ending a membership (slice C1, design §1)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated group, with the other
| routes/api/cda/*.php files. A member route rather than a /cda one: ending a
| membership is a member action (`borrowers:update`, checked in the form
| request), which the CDA reports then count.
*/
Route::patch('/borrowers/{borrower}/end-membership', [MembershipController::class, 'end'])->whereNumber('borrower');
