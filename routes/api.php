<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\CheckTokenExpiry;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

// Auth
Route::post('/auth/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware(['auth:sanctum', CheckTokenExpiry::class, EnsureUserIsActive::class])->group(function () {

    // Auth
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);

    // Users
    Route::apiResource('users', UserController::class)->except(['destroy']);
    Route::patch('/users/{user}/deactivate', [UserController::class, 'deactivate']);
    Route::patch('/users/{user}/reactivate', [UserController::class, 'reactivate']);
    Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword']);

    // Branches
    Route::apiResource('branches', BranchController::class)->except(['destroy']);

    // Roles (read-only)
    Route::get('/roles', [RoleController::class, 'index']);
    Route::get('/roles/{role}', [RoleController::class, 'show']);

    // Audit Logs (read-only)
    Route::get('/audit-logs', [AuditLogController::class, 'index']);
    Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show']);
});
