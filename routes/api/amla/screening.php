<?php

use App\Http\Controllers\Api\Amla\AmlaOrderController;
use App\Http\Controllers\Api\Amla\AmlaScreeningController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AMLA screening, orders and savings holds (Phase 6, AMLA-B5)
|--------------------------------------------------------------------------
|
| Loaded by routes/api.php inside the authenticated staff group. Every route
| is named `amla.*` and passes the `amla` limiter. Screening needs
| `amla:screening`, orders `amla:sanctions`, both with `branches:view_all`,
| checked before any record is looked up, so ids are plain `{id}`, bounded to
| 18 digits, never bound to a model. Lists come from uploads only.
*/
Route::prefix('amla')->name('amla.')->middleware('throttle:amla')->group(function () {
    Route::get('/screening/lists', [AmlaScreeningController::class, 'lists'])->name('screening.lists');
    Route::post('/screening/lists/{source}/versions', [AmlaScreeningController::class, 'upload'])->where('source', 'un|amlc|internal')->name('screening.lists.upload');
    Route::post('/screening/rescreen', [AmlaScreeningController::class, 'rescreen'])->name('screening.rescreen');
    Route::get('/screening/matches', [AmlaScreeningController::class, 'matches'])->name('screening.matches');
    Route::get('/screening/matches/{id}', [AmlaScreeningController::class, 'match'])->where('id', '[0-9]{1,18}')->name('screening.matches.show');
    Route::post('/screening/matches/{id}/confirm', [AmlaScreeningController::class, 'confirm'])->where('id', '[0-9]{1,18}')->name('screening.matches.confirm');
    Route::post('/screening/matches/{id}/clear', [AmlaScreeningController::class, 'clear'])->where('id', '[0-9]{1,18}')->name('screening.matches.clear');

    Route::get('/orders', [AmlaOrderController::class, 'index'])->name('orders.index');
    Route::post('/orders', [AmlaOrderController::class, 'store'])->name('orders.store');
    Route::get('/orders/{id}', [AmlaOrderController::class, 'show'])->where('id', '[0-9]{1,18}')->name('orders.show');
    Route::post('/orders/{id}/release', [AmlaOrderController::class, 'release'])->where('id', '[0-9]{1,18}')->name('orders.release');
});
