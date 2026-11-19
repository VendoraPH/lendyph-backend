<?php

namespace App\Providers;

use App\Services\LoanOrigination\Files\ClamAvScanner;
use App\Services\LoanOrigination\Files\FileScanner;
use App\Services\LoanOrigination\Files\NoScanner;
use Illuminate\Support\ServiceProvider;

/**
 * The loan origination module's wiring (Phase 6): the public application
 * (routes/api/public-apply/) and the staff side. Its rate limiters go here,
 * never in AppServiceProvider, as the portal's go in PortalServiceProvider.
 *
 * The upload scanner is chosen by `loan_origination.scanner.driver`: `none`
 * (the default, files kept as "Not scanned") or `clamav`. An unknown driver
 * falls back to none, so a typo can't take uploads down; the settings screen
 * shows which scanner is in use.
 */
class LoanOriginationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FileScanner::class, fn (): FileScanner => match (config('loan_origination.scanner.driver')) {
            'clamav' => ClamAvScanner::fromConfig(),
            default => new NoScanner,
        });
    }

    public function boot(): void
    {
        // Loan origination rate limiters are registered here by the loan origination slices.
    }
}
