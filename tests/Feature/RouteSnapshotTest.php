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
 * order), nor any schedule.
 *
 * New routes and schedules are allowed and need no fixture change, so the
 * module slices never edit one shared file. Every route and schedule in the
 * fixture must still be there, exactly as it was and in the same order
 * relative to the others; a route is found by its methods and URI, and a
 * schedule by its command and expression.
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

/**
 * The current entries that the fixture also has, in their current order, so a
 * changed, missing or reordered entry fails and an added one does not.
 *
 * @param  list<array<string, mixed>>  $current
 * @param  list<array<string, mixed>>  $fixture
 * @param  list<string>  $keyFields
 * @return list<array<string, mixed>>
 */
function snapshotEntriesInFixture(array $current, array $fixture, array $keyFields): array
{
    $key = fn (array $entry) => implode(' ', array_map(fn (string $field) => (string) $entry[$field], $keyFields));
    $fixtureKeys = array_flip(array_map($key, $fixture));

    return array_values(array_filter($current, fn (array $entry) => isset($fixtureKeys[$key($entry)])));
}

it('registers every route exactly as before, in the same order', function () {
    $fixture = routeSnapshotFixture('routes');

    expect(snapshotEntriesInFixture(registeredRouteSnapshot(), $fixture, ['methods', 'uri']))->toBe($fixture);
});

it('schedules every command exactly as before', function () {
    $fixture = routeSnapshotFixture('schedule');

    expect(snapshotEntriesInFixture(scheduledCommandSnapshot(), $fixture, ['command', 'expression']))->toBe($fixture);
});
