<?php

use App\Http\Controllers\Api\ReminderDashboardController;
use App\Http\Controllers\Api\ReminderHistoryController;
use App\Http\Controllers\Api\ReminderLoanController;
use App\Http\Controllers\Api\ReminderManualSendController;
use App\Http\Controllers\Api\ReminderPauseController;
use App\Http\Controllers\Api\ReminderPreferenceController;
use App\Http\Controllers\Api\ReminderQueueController;
use App\Http\Controllers\Api\ReminderRuleController;
use App\Http\Controllers\Api\ReminderSettingController;
use App\Http\Controllers\Api\ReminderTemplateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Payment reminders
|--------------------------------------------------------------------------
|
| The reminders module's 22 endpoints, exactly as the frontend's
| `API_ENDPOINTS.REMINDERS` and `reminder.service.ts` call them. Required
| from routes/api.php inside the authenticated group. Permissions are checked
| in the controllers and form requests (`reminders:view`, `send`, `pause`,
| `settings`). Literal segments come before `{id}` ones, and every id is a
| number. `{loan}` and `{borrower}` bind only rows in the caller's branches.
*/
Route::prefix('reminders')->group(function () {
    Route::get('/dashboard', [ReminderDashboardController::class, 'show']);

    Route::get('/rules', [ReminderRuleController::class, 'index']);
    Route::post('/rules', [ReminderRuleController::class, 'store']);
    Route::put('/rules/{id}', [ReminderRuleController::class, 'update'])->whereNumber('id');
    Route::post('/rules/{id}/deactivate', [ReminderRuleController::class, 'deactivate'])->whereNumber('id');

    Route::get('/templates', [ReminderTemplateController::class, 'index']);
    Route::post('/templates', [ReminderTemplateController::class, 'store']);
    Route::post('/templates/preview', [ReminderTemplateController::class, 'preview']);
    Route::put('/templates/{id}', [ReminderTemplateController::class, 'update'])->whereNumber('id');

    Route::get('/queue', [ReminderQueueController::class, 'index']);

    Route::get('/history', [ReminderHistoryController::class, 'index']);
    Route::get('/history/{id}', [ReminderHistoryController::class, 'show'])->whereNumber('id');

    Route::post('/manual-send/preview', [ReminderManualSendController::class, 'preview']);
    Route::post('/manual-send', [ReminderManualSendController::class, 'send']);

    Route::get('/pauses', [ReminderPauseController::class, 'index']);
    Route::post('/pause', [ReminderPauseController::class, 'pause']);
    Route::post('/resume', [ReminderPauseController::class, 'resume']);

    Route::get('/settings', [ReminderSettingController::class, 'show']);
    Route::put('/settings', [ReminderSettingController::class, 'update']);
});

Route::get('/loans/{loan}/reminders', [ReminderLoanController::class, 'show'])->whereNumber('loan');
Route::get('/borrowers/{borrower}/notification-preferences', [ReminderPreferenceController::class, 'show'])->whereNumber('borrower');
Route::put('/borrowers/{borrower}/notification-preferences', [ReminderPreferenceController::class, 'update'])->whereNumber('borrower');
