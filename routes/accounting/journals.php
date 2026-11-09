<?php

use App\Http\Controllers\Api\AccountingJournalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Journals (accounting module partial)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the /accounting group, after its own
| routes. `/journals/preview` can't collide with `/journals/{journal}`, whose
| wildcard is constrained with whereNumber.
*/

Route::post('/journals/preview', [AccountingJournalController::class, 'preview']);
