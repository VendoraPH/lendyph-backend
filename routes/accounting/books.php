<?php

use App\Http\Controllers\Api\AccountingBookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Column books
|--------------------------------------------------------------------------
|
| The cash receipts and cash disbursements books laid out in columns, one
| row per journal. Required from routes/api.php inside the /accounting
| group, so these paths are relative to /accounting. Both are literal
| paths below /books/cash-receipts and /books/cash-disbursements, which
| stay the line books, unchanged.
*/

Route::get('/books/cash-receipts/columns', [AccountingBookController::class, 'cashReceiptsColumns']);
Route::get('/books/cash-disbursements/columns', [AccountingBookController::class, 'cashDisbursementsColumns']);
