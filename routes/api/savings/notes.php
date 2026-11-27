<?php

use App\Http\Controllers\Api\Savings\SavingsAccountNoteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Savings & Deposits: account notes (gap round S4)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated group, with the other
| routes/api/savings/*.php files. Reading needs savings:view and adding
| savings:post; another branch's account answers 404.
*/
Route::prefix('savings')->group(function () {
    Route::get('/accounts/{account}/notes', [SavingsAccountNoteController::class, 'index'])->whereNumber('account');
    Route::post('/accounts/{account}/notes', [SavingsAccountNoteController::class, 'store'])->whereNumber('account');
});
