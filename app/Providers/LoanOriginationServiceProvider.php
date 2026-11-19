<?php

namespace App\Providers;

use App\Services\LoanOrigination\Files\ClamAvScanner;
use App\Services\LoanOrigination\Files\FileScanner;
use App\Services\LoanOrigination\Files\MisconfiguredScanner;
use App\Services\LoanOrigination\Files\NoScanner;
use Illuminate\Support\ServiceProvider;

/**
 * The loan origination module's wiring (Phase 6): the public application
 * (routes/api/public-apply/) and the staff side. Its rate limiters go here,
 * never in AppServiceProvider, as the portal's go in PortalServiceProvider.
 *
 * The upload scanner is chosen by `loan_origination.scanner.driver`, read
 * without regard to case or spaces: `none` (the default, or blank; files are
 * kept as "Not scanned") or `clamav`. Any other value is a misconfiguration,
 * never "no scanner": uploads are refused (503) until it is fixed, and the
 * settings screen says so.
 */
class LoanOriginationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FileScanner::class, fn (): FileScanner => match (strtolower(trim((string) config('loan_origination.scanner.driver', 'none')))) {
            '', 'none' => new NoScanner,
            'clamav' => ClamAvScanner::fromConfig(),
            default => new MisconfiguredScanner,
        });
    }

    public function boot(): void
    {
        // Loan origination rate limiters are registered here by the loan origination slices.
    }
}
