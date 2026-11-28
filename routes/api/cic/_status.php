<?php

use App\Http\Controllers\Api\Cic\CicStatusController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CIC reporting: status (foundation)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated group, with the other
| routes/api/cic/*.php files, in file-name order: settings.php (settings and
| keys), records.php (records and captures), submissions.php (files). Each
| file sets the `/cic` prefix. Every route answers 404 while CIC_ENABLED is
| off or Menu Visibility hides `cic`; each needs `branches:view_all` plus its
| own `cic:*` permission, checked in the controllers (CicController).
*/
Route::prefix('cic')->group(function () {
    Route::get('/status', [CicStatusController::class, 'show']);
});
