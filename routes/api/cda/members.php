<?php

use App\Http\Controllers\Api\BorrowerMembershipController;
use App\Http\Controllers\Api\Cda\MembershipController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CDA Reports: ending a membership (slice C1, design §1), and the member
| page's membership details (finish run round 2, decision 15)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated group, with the other
| routes/api/cda/*.php files. Member routes rather than /cda ones: ending a
| membership and changing its date, type or number are member actions
| (`borrowers:update`, checked in the form requests), which the CDA reports
| then count. Both are nested under {borrower}, never a fixed word beside it.
*/
Route::patch('/borrowers/{borrower}/end-membership', [MembershipController::class, 'end'])->whereNumber('borrower');
Route::patch('/borrowers/{borrower}/membership', [BorrowerMembershipController::class, 'update'])->whereNumber('borrower');
