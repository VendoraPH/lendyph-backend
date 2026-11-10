<?php

use App\Http\Controllers\Api\Cda\CdaExportController;
use App\Http\Controllers\Api\Cda\CdaLoanClassificationController;
use App\Http\Controllers\Api\Cda\CdaPesosController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CDA Reports: loan classification, Enhanced PESOS and exports (slice C4)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated group, with the other
| routes/api/cda/*.php files. Every route needs `branches:view_all` plus its
| own `cda:*` permission (CdaAccess, checked in the controllers). The
| classification and PESOS screens are read only: they have no write routes.
| Export downloads use the signed `files.document` link; there is no new
| public route.
*/
Route::prefix('cda')->group(function () {
    Route::get('/periods/{year}/loan-classification', [CdaLoanClassificationController::class, 'show'])->whereNumber('year');
    Route::get('/periods/{year}/pesos', [CdaPesosController::class, 'show'])->whereNumber('year');

    Route::get('/reports/{report}/exports', [CdaExportController::class, 'index'])->whereNumber('report');
    Route::post('/reports/{report}/exports', [CdaExportController::class, 'store'])->whereNumber('report');
});
