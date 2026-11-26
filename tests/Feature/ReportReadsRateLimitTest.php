<?php

namespace Tests\Feature;

use App\Models\PortalUser;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The Executive Dashboard's first load gets no "too many requests" (finish
 * run round 7; round 6's BC12b saw about 10 of its report calls answer 429).
 *
 * The page asks 17 things at once: its 6 own endpoints, 9 reports, the
 * accounting dashboard and one loan list row, plus the shell's own calls, all
 * on the shared `api` limiter's 60 a minute per user (twice over in a
 * development build, where React runs each effect twice). Dashboard and report
 * reads (GET under /api/dashboard/, /api/reports/ and /api/accounting/dashboard)
 * now have their own bucket of 120 a minute per staff user, so a full load
 * (16 reads, 32 in development) fits with room for a few reloads, and leaves the
 * shared 60 to everything else. Writes and every other route stay on the shared
 * 60; exports keep their own 5 a minute on top.
 */
class ReportReadsRateLimitTest extends TestCase
{
    use SetupLendyPH;

    /** The Executive Dashboard's reads that go to the bucket, as its first load sends them. */
    private const EXECUTIVE_LOAD = [
        'api/dashboard/executive/overview',
        'api/dashboard/executive/branches',
        'api/dashboard/executive/attention',
        'api/dashboard/executive/trends',
        'api/dashboard/executive/turnaround',
        'api/dashboard/executive/share-capital-control',
        'api/reports/loan-balance-summary',
        'api/reports/aging',
        'api/reports/cash-flow',
        'api/reports/collection-efficiency',
        'api/reports/portfolio-by-product',
        'api/reports/performance',
        'api/reports/share-capital',
        'api/reports/borrowers',
        'api/reports/disbursements',
        'api/accounting/dashboard',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function limitFor(string $method, string $uri, User|PortalUser|null $caller, ?string $routeName = null): Limit
    {
        $request = Request::create('/'.$uri, $method);
        $route = new RoutingRoute([$method], $uri, []);

        if ($routeName !== null) {
            $route->name($routeName);
        }

        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $caller);

        $limit = RateLimiter::limiter('api')($request);
        $this->assertInstanceOf(Limit::class, $limit);

        return $limit;
    }

    public function test_every_read_of_the_executive_dashboards_load_is_a_real_get_route(): void
    {
        foreach (self::EXECUTIVE_LOAD as $uri) {
            $this->assertNotNull(
                collect(Route::getRoutes()->get('GET'))->first(fn (RoutingRoute $route) => $route->uri() === $uri),
                "{$uri} is not a GET route, so the bucket below doesn't cover what the page sends.",
            );
        }
    }

    public function test_the_dashboards_reads_share_one_bucket_of_120_a_minute_per_user(): void
    {
        foreach (self::EXECUTIVE_LOAD as $uri) {
            $limit = $this->limitFor('GET', $uri, $this->admin);

            $this->assertSame(120, $limit->maxAttempts, $uri);
            $this->assertSame(60, $limit->decaySeconds, $uri);
            $this->assertSame('report-reads:'.$this->admin->id, $limit->key, $uri);
        }
    }

    public function test_one_full_load_fits_twice_over(): void
    {
        $limit = $this->limitFor('GET', self::EXECUTIVE_LOAD[0], $this->admin);

        $this->assertGreaterThanOrEqual(2 * count(self::EXECUTIVE_LOAD), $limit->maxAttempts);
    }

    public function test_the_bucket_is_per_user_and_apart_from_the_shared_sixty(): void
    {
        $other = User::factory()->create(['branch_id' => $this->branch->id]);

        $mine = $this->limitFor('GET', 'api/reports/aging', $this->admin);
        $theirs = $this->limitFor('GET', 'api/reports/aging', $other);
        $shared = $this->limitFor('GET', 'api/loans', $this->admin);

        $this->assertNotSame($mine->key, $theirs->key);
        $this->assertSame(60, $shared->maxAttempts);
        $this->assertSame($this->admin->id, $shared->key);
        $this->assertNotSame($mine->key, $shared->key);
    }

    /** @return array<string, array{string, string}> */
    public static function stayOnTheSharedSixty(): array
    {
        return [
            'a dashboard settings write' => ['PUT', 'api/dashboard/settings'],
            'a report-looking write' => ['POST', 'api/reports/aging'],
            'the loan list' => ['GET', 'api/loans'],
            'another accounting read' => ['GET', 'api/accounting/trial-balance'],
            'a path that only starts like one' => ['GET', 'api/reportsx/aging'],
        ];
    }

    #[DataProvider('stayOnTheSharedSixty')]
    public function test_writes_and_other_routes_stay_on_the_shared_sixty(string $method, string $uri): void
    {
        $limit = $this->limitFor($method, $uri, $this->admin);

        $this->assertSame(60, $limit->maxAttempts);
        $this->assertSame($this->admin->id, $limit->key);
    }

    public function test_a_caller_who_is_not_staff_gets_no_bucket(): void
    {
        $limit = $this->limitFor('GET', 'api/reports/aging', null);

        $this->assertSame(60, $limit->maxAttempts);
        $this->assertStringStartsNotWith('report-reads:', (string) $limit->key);
    }
}
