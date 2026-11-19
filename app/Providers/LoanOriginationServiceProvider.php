<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * The loan origination module's wiring (Phase 6): the public application
 * (routes/api/public-apply/) and the staff side. Its rate limiters go here,
 * never in AppServiceProvider, as the portal's go in PortalServiceProvider.
 */
class LoanOriginationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Loan origination rate limiters are registered here by the loan origination slices.
    }
}
