<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Admin bypass — admin role gets all permissions automatically
        Gate::before(function ($user) {
            return $user->hasRole('admin') ? true : null;
        });
    }
}
