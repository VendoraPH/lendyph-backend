<?php

use App\Http\Controllers\Api\AccountingAttachmentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Attachments on journals and expenses (accounting module partial)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the /accounting group, so these paths
| are relative to /accounting. Each route names its owner with the literal
| segment `journals` or `expenses`, and every wildcard is constrained with
| whereNumber, so nothing is captured as an id. Permissions are checked in
| the controller and the form requests (AccountingAttachmentController).
*/

Route::get('/journals/{journal}/attachments', [AccountingAttachmentController::class, 'journalIndex'])->whereNumber('journal');
Route::post('/journals/{journal}/attachments', [AccountingAttachmentController::class, 'store'])->whereNumber('journal');
Route::delete('/journals/{journal}/attachments/{attachment}', [AccountingAttachmentController::class, 'destroy'])->whereNumber(['journal', 'attachment']);

Route::get('/expenses/{expense}/attachments', [AccountingAttachmentController::class, 'expenseIndex'])->whereNumber('expense');
Route::post('/expenses/{expense}/attachments', [AccountingAttachmentController::class, 'store'])->whereNumber('expense');
Route::delete('/expenses/{expense}/attachments/{attachment}', [AccountingAttachmentController::class, 'destroy'])->whereNumber(['expense', 'attachment']);
