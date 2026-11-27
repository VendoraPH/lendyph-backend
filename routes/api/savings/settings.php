<?php

use App\Http\Controllers\Api\Savings\SavingsProductController;
use App\Http\Controllers\Api\Savings\SavingsSettingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Savings & Deposits: settings, tax rules, products and rates (slice S1)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated group, with the other
| routes/api/savings/*.php files. Permissions are checked in the controllers
| and form requests (`savings:view`, `savings:settings`). Rates are
| append-only: there is no route to change or remove one.
*/
Route::prefix('savings')->group(function () {
    Route::get('/settings', [SavingsSettingController::class, 'show']);
    Route::put('/settings', [SavingsSettingController::class, 'update']);
    Route::post('/settings/prepare-books', [SavingsSettingController::class, 'prepareBooks']);
    Route::post('/tax-rules', [SavingsSettingController::class, 'storeTaxRule']);

    Route::get('/products', [SavingsProductController::class, 'index']);
    Route::post('/products', [SavingsProductController::class, 'store']);
    Route::put('/products/{product}', [SavingsProductController::class, 'update'])->whereNumber('product');
    Route::delete('/products/{product}', [SavingsProductController::class, 'destroy'])->whereNumber('product');
    Route::get('/products/{product}/rates', [SavingsProductController::class, 'rates'])->whereNumber('product');
    Route::post('/products/{product}/rates', [SavingsProductController::class, 'storeRate'])->whereNumber('product');
});
