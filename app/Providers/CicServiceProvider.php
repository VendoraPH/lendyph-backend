<?php

namespace App\Providers;

use App\Services\Cic\CicSwitch;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * The CIC reporting module's wiring. Production refuses to boot with test
 * captures on (`CIC_TEST_CAPTURES`), as LoanOriginationServiceProvider refuses
 * the test-code mode: a test capture is dated today on any day, so it could
 * never be a real month-end figure.
 */
class CicServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->environment('production') && config('cic.test_captures') === true) {
            throw new RuntimeException(CicSwitch::PRODUCTION_REFUSAL);
        }
    }
}
