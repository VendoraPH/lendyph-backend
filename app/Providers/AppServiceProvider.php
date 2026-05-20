<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Super-admin bypass — developer-side super_admin role gets all
        // permissions automatically. Client-side "admin" no longer bypasses
        // here; instead, the RoleAndPermissionSeeder gives the admin role
        // every permission directly, so this hook only matters as a safety
        // net for the platform team.
        Gate::before(function ($user) {
            return $user->hasRole('super_admin') ? true : null;
        });

        // API rate limiters
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for('exports', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });

        // Public registration (anonymous borrower submission + the two
        // X-Submission-Token upload endpoints). 5 requests / 10 minutes / IP
        // is enough for the legitimate 3-step flow plus retries while still
        // shutting down abuse. Authenticated callers (operators creating
        // borrowers in bulk from a shared office IP) bypass the limit so
        // they don't share quota with public registrants.
        RateLimiter::for('public-registration', function (Request $request) {
            if ($request->user()) {
                return Limit::none();
            }

            return Limit::perMinutes(10, 5)->by($request->ip());
        });
    }
}
