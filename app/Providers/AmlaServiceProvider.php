<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * The AMLA module's wiring (Phase 6). Its rate limiters go here, never in
 * AppServiceProvider, as the portal's go in PortalServiceProvider.
 */
class AmlaServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // AMLA rate limiters are registered here by the AMLA slices.
    }
}
