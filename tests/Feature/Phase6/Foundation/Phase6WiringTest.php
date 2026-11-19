<?php

use App\Providers\AmlaServiceProvider;
use App\Providers\LoanOriginationServiceProvider;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\File;
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

it('loads a route file dropped in each Phase 6 folder, staff folders inside the authenticated group and public-apply outside it', function () {
    withPhase6RoutesCopy(function (string $copy): void {

        foreach ([...PHASE6_STAFF_ROUTE_FOLDERS, 'public-apply'] as $folder) {
            File::put("{$copy}/api/{$folder}/zz-probe.php", sprintf(
                "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/x0-probe/%s', fn () => 'ok')->name('x0-probe.%s');\n",
                $folder,
                $folder,
            ));
        }

        $router = new Router(app('events'), app());
        app()->instance('router', $router);
        Route::clearResolvedInstance('router');

        Route::prefix('api')->group("{$copy}/api.php");
        $router->getRoutes()->refreshNameLookups();

        foreach (PHASE6_STAFF_ROUTE_FOLDERS as $folder) {
            $route = $router->getRoutes()->getByName("x0-probe.{$folder}");

            expect($route)->not->toBeNull("routes/api/{$folder}/*.php is not loaded")
                ->and($route->uri())->toBe("api/x0-probe/{$folder}")
                ->and($route->gatherMiddleware())->toContain('auth:sanctum');
        }

        $public = $router->getRoutes()->getByName('x0-probe.public-apply');

        expect($public)->not->toBeNull('routes/api/public-apply/*.php is not loaded')
            ->and($public->uri())->toBe('api/x0-probe/public-apply')
            ->and($public->gatherMiddleware())->not->toContain('auth:sanctum');
    });
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
