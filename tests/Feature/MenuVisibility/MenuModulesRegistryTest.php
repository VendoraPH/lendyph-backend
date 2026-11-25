<?php

use App\Services\MenuVisibility\MenuModules;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class);

/*
 * The module registry against the real route table (finish run round 6): every
 * prefix it blocks, and every route it keeps open, names a route that exists,
 * so a typo can never leave a hidden module's API answering.
 */

/** @return list<array{method: string, uri: string}> */
function apiRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var RoutingRoute $route */
        if (! str_starts_with($route->uri(), 'api/')) {
            continue;
        }

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $routes[] = ['method' => $method, 'uri' => $route->uri()];
        }
    }

    return $routes;
}

it('holds the contract\'s modules, labels and parents, in the side menu\'s order', function () {
    $modules = array_map(
        fn (string $key) => [$key, MenuModules::label($key), MenuModules::parentOf($key)],
        MenuModules::keys(),
    );

    expect($modules)->toBe([
        ['dashboard', 'Dashboard', null],
        ['executive_dashboard', 'Executive Dashboard', null],
        ['linda', 'Linda', null],
        ['members', 'Members', null],
        ['loans', 'Loans', null],
        ['loans.reminders', 'Reminders', 'loans'],
        ['loans.origination', 'Loan Origination', 'loans'],
        ['payments', 'Payments', null],
        ['payments.auto_pay', 'Auto-Pay', 'payments'],
        ['share_capital', 'Share Capital', null],
        ['share_capital.auto_credit', 'Auto-Credit', 'share_capital'],
        ['savings', 'Savings', null],
        ['collateral', 'Collateral', null],
        ['gcash', 'GCash', null],
        ['credit_scoring', 'Credit Scoring', null],
        ['cda', 'CDA Compliance', null],
        ['member_portal', 'Member Portal', null],
        ['amla', 'AMLA Compliance', null],
        ['accounting', 'Accounting', null],
        ['users', 'User Management', null],
        ['reports', 'Reports', null],
        ['documents', 'Documents', null],
        ['audit_trail', 'Audit Trail', null],
    ]);
});

it('blocks only prefixes that name real routes', function () {
    $routes = apiRoutes();
    $unmatched = [];

    foreach (MenuModules::keys() as $key) {
        foreach ([...MenuModules::blocks($key), ...array_keys(MenuModules::keptOpen($key))] as $entry) {
            $matched = array_filter($routes, fn (array $route) => MenuModules::entryMatches($entry, $route['method'], MenuModules::segmentsOf($route['uri'])));

            if ($matched === []) {
                $unmatched[] = "{$key}: {$entry}";
            }
        }
    }

    expect($unmatched)->toBe([]);
});

it('keeps open only routes its own module would block', function () {
    foreach (MenuModules::keys() as $key) {
        foreach (array_keys(MenuModules::keptOpen($key)) as $entry) {
            [$method, $path] = explode(' ', $entry, 2);

            expect(MenuModules::blockingModule($method, 'api/'.$path, [$key]))->toBeNull()
                ->and(collect(MenuModules::blocks($key))->contains(fn (string $block) => MenuModules::entryMatches($block, $method, explode('/', $path))))->toBeTrue("{$key} keeps open {$entry}, which it never blocks.");
        }
    }
});

it('never blocks sign-in, the user\'s own details, health, notifications or Menu Visibility', function () {
    $everything = MenuModules::keys();

    foreach (apiRoutes() as $route) {
        if (! in_array(MenuModules::segmentsOf($route['uri'])[0] ?? '', MenuModules::ALWAYS_OPEN, true)) {
            continue;
        }

        expect(MenuModules::blockingModule($route['method'], $route['uri'], $everything))->toBeNull("{$route['method']} /{$route['uri']}");
    }

    expect(MenuModules::ALWAYS_OPEN)->toBe(['auth', 'health', 'notifications', 'menu-visibility']);
});

it('blocks a hidden module\'s whole own API and nothing of a module that is shown', function () {
    $blocked = collect(apiRoutes())
        ->filter(fn (array $route) => MenuModules::blockingModule($route['method'], $route['uri'], ['savings']) !== null)
        ->map(fn (array $route) => MenuModules::segmentsOf($route['uri'])[0])
        ->unique()->sort()->values()->all();

    expect($blocked)->toBe(['portal', 'reports', 'savings'])
        ->and(MenuModules::blockingModule('GET', 'api/savings/accounts', ['savings']))->toBeNull()
        ->and(MenuModules::blockingModule('GET', 'api/savings/accounts/{account}', ['savings']))->toBe('savings')
        ->and(MenuModules::blockingModule('GET', 'api/savings/accounts/{account}', ['gcash', 'linda']))->toBeNull();
});
