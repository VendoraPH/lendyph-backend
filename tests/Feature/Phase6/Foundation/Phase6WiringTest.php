<?php

use App\Http\Controllers\Api\LoanController;
use App\Http\Middleware\CheckTokenExpiry;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RequirePasswordChange;
use App\Providers\AmlaServiceProvider;
use App\Providers\LoanOriginationServiceProvider;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Phase 6 wave 0 (slice X0): the route and console folders each Phase 6 slice
 * adds its own files to, and the two providers that hold its rate limiters.
 *
 * The globs are tested by loading a COPY of routes/ with a probe file dropped
 * in each folder, into a fresh router and schedule, so the real folders are
 * never written to and a parallel worker never sees a probe.
 */
uses(TestCase::class);

const PHASE6_STAFF_ROUTE_FOLDERS = ['amla', 'branches', 'dashboard', 'loan-origination'];

const PHASE6_CONSOLE_FOLDERS = ['amla', 'loan-origination'];

/**
 * Runs `$probe` on a copy of routes/ in a temporary folder, removed afterwards.
 *
 * @param  Closure(string): void  $probe
 */
function withPhase6RoutesCopy(Closure $probe): void
{
    $copy = sys_get_temp_dir().'/lendyph-x0-routes-'.Str::random(16);
    File::copyDirectory(base_path('routes'), $copy);

    try {
        $probe($copy);
    } finally {
        File::deleteDirectory($copy);
    }
}

it('keeps each Phase 6 folder in the repository', function (string $folder) {
    expect(is_dir(base_path("routes/{$folder}")))->toBeTrue();
})->with([
    'api/amla', 'api/branches', 'api/dashboard', 'api/loan-origination', 'api/public-apply',
    'console/amla', 'console/loan-origination',
]);

/**
 * A fresh router in place of the app's, holding `$routes` (a plain collection
 * by default), with routes/api.php from `$routesFolder` loaded under /api.
 */
function phase6LoadApiRoutes(string $routesFolder, ?RouteCollection $routes = null): Router
{
    $router = new Router(app('events'), app());
    $router->setRoutes($routes ?? new RouteCollection);
    app()->instance('router', $router);
    Route::clearResolvedInstance('router');

    Route::prefix('api')->group("{$routesFolder}/api.php");
    $router->getRoutes()->refreshNameLookups();

    return $router;
}

/**
 * A route collection that records every route added under a method, domain
 * and URI already taken. Laravel's own collection replaces such a route in
 * place, so a later file re-declaring a route would silently take over its
 * controller and checks.
 */
function phase6DuplicateCatchingRoutes(): RouteCollection
{
    return new class extends RouteCollection
    {
        /** @var array<string, true> */
        private array $seen = [];

        /** @var list<string> */
        public array $duplicates = [];

        public function add(RoutingRoute $route)
        {
            foreach ($route->methods() as $method) {
                $key = $method.' '.$route->getDomain().'/'.$route->uri();

                if (isset($this->seen[$key])) {
                    $this->duplicates[] = $key;
                }

                $this->seen[$key] = true;
            }

            return parent::add($route);
        }
    };
}

/** The staff group's middleware, in routes/api.php. */
const PHASE6_STAFF_MIDDLEWARE = ['auth:sanctum', CheckTokenExpiry::class, EnsureUserIsActive::class, RequirePasswordChange::class];

it('loads a route file dropped in each Phase 6 folder, staff folders inside the authenticated group and public-apply outside it', function () {
    withPhase6RoutesCopy(function (string $copy): void {
        foreach ([...PHASE6_STAFF_ROUTE_FOLDERS, 'public-apply'] as $folder) {
            File::put("{$copy}/api/{$folder}/zz-probe.php", sprintf(
                "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/x0-probe/%s', fn () => 'ok')->name('x0-probe.%s');\n",
                $folder,
                $folder,
            ));
        }

        $router = phase6LoadApiRoutes($copy);

        foreach (PHASE6_STAFF_ROUTE_FOLDERS as $folder) {
            $route = $router->getRoutes()->getByName("x0-probe.{$folder}");

            expect($route)->not->toBeNull("routes/api/{$folder}/*.php is not loaded")
                ->and($route->uri())->toBe("api/x0-probe/{$folder}");

            foreach (PHASE6_STAFF_MIDDLEWARE as $middleware) {
                expect($route->gatherMiddleware())->toContain($middleware);
            }
        }

        $public = $router->getRoutes()->getByName('public-apply.x0-probe.public-apply');

        expect($public)->not->toBeNull('routes/api/public-apply/*.php is not loaded')
            ->and($public->uri())->toBe('api/public-apply/x0-probe/public-apply');

        foreach (PHASE6_STAFF_MIDDLEWARE as $middleware) {
            expect($public->gatherMiddleware())->not->toContain($middleware);
        }
    });
});

it('keeps a public-apply file that forgets its prefix under /api/public-apply, so it never answers a staff route', function () {
    withPhase6RoutesCopy(function (string $copy): void {
        File::put("{$copy}/api/public-apply/zz-probe.php", "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/loans/{x}', fn () => 'public')->name('probe');\n");

        $router = phase6LoadApiRoutes($copy);

        $public = $router->getRoutes()->getByName('public-apply.probe');
        expect($public)->not->toBeNull()
            ->and($public->uri())->toBe('api/public-apply/loans/{x}');

        $staff = $router->getRoutes()->match(Request::create('/api/loans/1', 'GET'));
        expect($staff->getActionName())->toBe(LoanController::class.'@show');

        foreach (PHASE6_STAFF_MIDDLEWARE as $middleware) {
            expect($staff->gatherMiddleware())->toContain($middleware);
        }
    });
});

it('names every public-apply route public-apply.*, so the shared api limiter exempts it', function () {
    $unnamed = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/public-apply'))
        ->reject(fn (RoutingRoute $route) => str_starts_with((string) $route->getName(), 'public-apply.'))
        ->map(fn (RoutingRoute $route) => $route->uri())
        ->values()
        ->all();

    expect($unnamed)->toBe([]);
});

it('registers no route twice under the same method, domain and URI', function () {
    $routes = phase6DuplicateCatchingRoutes();

    phase6LoadApiRoutes(base_path('routes'), $routes);

    expect(count($routes->getRoutes()))->toBeGreaterThan(100)
        ->and($routes->duplicates)->toBe([]);
});

it('catches a Phase 6 file that re-declares a staff route', function () {
    withPhase6RoutesCopy(function (string $copy): void {
        File::put("{$copy}/api/amla/zz-probe.php", "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/loans/{loan}', fn () => 'taken over');\n");

        $routes = phase6DuplicateCatchingRoutes();
        phase6LoadApiRoutes($copy, $routes);

        expect($routes->duplicates)->toContain('GET /api/loans/{loan}');
    });
});

it('exempts public-apply routes from the shared api limiter, and still meters other anonymous calls', function () {
    $limiter = RateLimiter::limiter('api');

    $requestFor = function (string $name, string $uri): Request {
        $request = Request::create('/'.$uri, 'GET');
        $route = new RoutingRoute(['GET'], $uri, []);
        $route->name($name);
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => null);

        return $request;
    };

    expect($limiter($requestFor('public-apply.probe', 'api/public-apply/probe')))->toBeInstanceOf(Unlimited::class)
        ->and($limiter($requestFor('branches.public', 'api/branches/public')))->not->toBeInstanceOf(Unlimited::class);
});

it('loads a schedule file dropped in each Phase 6 console folder', function () {
    withPhase6RoutesCopy(function (string $copy): void {

        foreach (PHASE6_CONSOLE_FOLDERS as $folder) {
            File::put("{$copy}/console/{$folder}/zz-probe.php", sprintf(
                "<?php\n\nuse Illuminate\\Support\\Facades\\Schedule;\n\nSchedule::command('x0-probe:%s')->dailyAt('01:00')->timezone('Asia/Manila');\n",
                $folder,
            ));
        }

        $schedule = new Schedule('Asia/Manila');
        app()->instance(Schedule::class, $schedule);
        ScheduleFacade::clearResolvedInstance(Schedule::class);

        require "{$copy}/console.php";

        $commands = collect($schedule->events())->map(fn (Event $event) => (string) $event->command)->all();

        foreach (PHASE6_CONSOLE_FOLDERS as $folder) {
            expect(collect($commands)->contains(fn (string $command) => str_contains($command, "x0-probe:{$folder}")))
                ->toBeTrue("routes/console/{$folder}/*.php is not loaded");
        }
    });
});

it('registers the AMLA and loan origination providers', function (string $provider) {
    expect(require base_path('bootstrap/providers.php'))->toContain($provider)
        ->and(app()->getProviders($provider))->toHaveCount(1);
})->with([AmlaServiceProvider::class, LoanOriginationServiceProvider::class]);
