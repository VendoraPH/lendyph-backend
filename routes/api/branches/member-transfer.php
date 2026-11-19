<?php

use App\Http\Controllers\Api\Branches\BorrowerTransferController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Members: transfer to another branch (Phase 6 MB-BE4)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated group. The path is
| nested under {borrower}, never a fixed word beside `borrowers/{borrower}`.
| It needs `borrowers:transfer` (TransferBorrowerRequest); a member the
| caller can't see answers 404.
*/
Route::post('borrowers/{borrower}/transfer', [BorrowerTransferController::class, 'store'])
    ->whereNumber('borrower')
    ->name('borrowers.transfer');
