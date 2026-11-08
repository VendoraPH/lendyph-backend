<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Every HTTP route and every scheduled command, compared with a list taken from
 * origin/development before the module route partials existed.
 *
 * routes/api.php and routes/console.php now pull in per-module partials
 * (routes/api/*.php, routes/accounting/*.php, routes/console/*.php). Moving a
 * block into a partial must not change a route's method, URI, name, action,
 * middleware, constraints or registration order (Laravel matches in that
 * order), nor any schedule. A module that adds routes on purpose updates the
 * fixture in the same PR, so the change shows in review.
 */
uses(TestCase::class);

/** @return list<array<string, mixed>> */
function registeredRouteSnapshot(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->map(fn (RoutingRoute $route) => [
            'methods' => implode('|', $route->methods()),
            'uri' => $route->uri(),
            'name' => $route->getName(),
            'action' => $route->getActionName(),
            'middleware' => array_values(array_map(
                fn ($middleware) => is_string($middleware) ? $middleware : get_debug_type($middleware),
                $route->gatherMiddleware(),
            )),
            'wheres' => $route->wheres,
        ])
        ->values()
        ->all();
}

/** @return list<array<string, mixed>> */
function scheduledCommandSnapshot(): array
{
    return collect(app(Schedule::class)->events())
        ->map(fn (Event $event) => [
            'command' => Str::after((string) $event->command, "'artisan' "),
            'expression' => $event->getExpression(),
            'timezone' => $event->timezone instanceof DateTimeZone ? $event->timezone->getName() : $event->timezone,
            'without_overlapping' => $event->withoutOverlapping,
            'expires_at' => $event->expiresAt,
        ])
        ->values()
        ->all();
}

/** @return list<array<string, mixed>> */
function routeSnapshotFixture(string $name): array
{
    return json_decode(
        file_get_contents(dirname(__DIR__).'/Fixtures/'.$name.'.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

it('registers every route exactly as before, in the same order', function () {
    expect(registeredRouteSnapshot())->toBe(routeSnapshotFixture('routes'));
});

it('schedules every command exactly as before', function () {
    expect(scheduledCommandSnapshot())->toBe(routeSnapshotFixture('schedule'));
});
