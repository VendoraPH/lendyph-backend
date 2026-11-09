<?php

use App\Http\Controllers\Api\AccountingAllowanceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Allowance for credit losses
|--------------------------------------------------------------------------
|
| The allowance preview, its permissioned posting (one journal per branch)
| and the postings list. Required from routes/api.php inside the
| /accounting group, so these paths are relative to /accounting. Literal
| paths only.
*/

Route::get('/allowance/preview', [AccountingAllowanceController::class, 'preview']);
Route::get('/allowance/postings', [AccountingAllowanceController::class, 'index']);
Route::post('/allowance/postings', [AccountingAllowanceController::class, 'store']);
