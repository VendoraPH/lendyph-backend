<?php

use App\Http\Controllers\Api\Branches\UserBranchHistoryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| A user's branch history (Phase 6 MB-BE6)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the authenticated group. Nested under
| the numeric {user}, never a fixed word beside it. Named inside `users.*`
| so user management's rate limiter and refusal audit cover it.
| UserBranchHistoryRequest needs `users:view` and the caller's reach (404).
*/
Route::get('/users/{user}/branch-history', [UserBranchHistoryController::class, 'show'])
    ->name('users.branch-history')
    ->whereNumber('user');
