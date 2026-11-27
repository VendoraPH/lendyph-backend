<?php

namespace Tests\Feature\Cda\Registers;

use App\Models\CdaReportingPeriod;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\Cda\PeriodOpener;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Cda\Foundation\CdaTestHelpers;

/**
 * Shared set-up for the CDA register tests (slice C3): the clock on
 * 2026-11-11 Manila, the 2026 reporting period, users whose only role holds
 * exactly the permissions named, and signed links read with no signed-in
 * user, as a browser tab sends them.
 */
trait RegisterHelpers
{
    use CdaTestHelpers;

    protected function freezeClock(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-11 09:00:00', 'Asia/Manila'));
    }

    protected function period(int $year = 2026): CdaReportingPeriod
    {
        return app(PeriodOpener::class)->ensure($year);
    }

    /**
     * A user holding `branches:view_all` and the permissions named.
     *
     * @param  list<string>  $permissions
     */
    protected function cdaUser(array $permissions): User
    {
        return $this->userWith([BranchAccess::VIEW_ALL, ...$permissions]);
    }

    protected function openAsBrowser(string $url): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->get($url);
    }
}
