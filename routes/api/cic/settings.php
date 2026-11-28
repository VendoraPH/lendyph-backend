<?php

use App\Http\Controllers\Api\Cic\CicCodeMappingController;
use App\Http\Controllers\Api\Cic\CicLoanOverrideController;
use App\Http\Controllers\Api\Cic\CicProductMappingController;
use App\Http\Controllers\Api\Cic\CicPublicKeyController;
use App\Http\Controllers\Api\Cic\CicSettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CIC reporting: settings and keys
|--------------------------------------------------------------------------
|
| Lender settings, CIC's public keys, product and code mappings, and per-loan
| overrides. Every route answers 404 while CIC is off or hidden, and needs
| `branches:view_all` plus its `cic:*` permission, checked in the
| controllers and form requests.
*/
Route::prefix('cic')->group(function () {
    Route::get('/settings', [CicSettingsController::class, 'show']);
    Route::put('/settings', [CicSettingsController::class, 'update']);

    Route::get('/public-keys', [CicPublicKeyController::class, 'index']);
    Route::post('/public-keys', [CicPublicKeyController::class, 'store']);
    Route::post('/public-keys/{key}/verify', [CicPublicKeyController::class, 'verify'])->whereNumber('key');
    Route::post('/public-keys/{key}/retire', [CicPublicKeyController::class, 'retire'])->whereNumber('key');

    Route::get('/product-mappings', [CicProductMappingController::class, 'index']);
    Route::put('/product-mappings', [CicProductMappingController::class, 'update']);

    Route::get('/loan-overrides', [CicLoanOverrideController::class, 'index']);
    Route::post('/loan-overrides', [CicLoanOverrideController::class, 'store']);
    Route::put('/loan-overrides/{override}', [CicLoanOverrideController::class, 'update'])->whereNumber('override');
    Route::delete('/loan-overrides/{override}', [CicLoanOverrideController::class, 'destroy'])->whereNumber('override');

    Route::get('/code-mappings', [CicCodeMappingController::class, 'index']);
    Route::put('/code-mappings', [CicCodeMappingController::class, 'update']);
});
