<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The Credit Scoring endpoints answer 501 until the module is built.
 *
 * The frontend reads only 404 and 501 as "not connected yet", so a permitted
 * caller must get 501, never a 403 or a 500. A caller without the permission
 * gets 403. Every caller here is a non-super_admin user: super_admin passes
 * every check through Gate::before and would prove nothing about the
 * permission each route checks.
 */
uses(TestCase::class);

/**
 * Method, path and the permission the handoff assigns, for every endpoint the
 * frontend calls. Not `GET /alerts`: alerts arrive inside risk-monitoring.
 *
 * @return array<string, array{string, string, string}>
 */
function creditScoringPlaceholderEndpoints(): array
{
    return [
        'GET dashboard' => ['GET', '/api/credit-scoring/dashboard', 'credit_scoring:view'],
        'GET borrowers' => ['GET', '/api/credit-scoring/borrowers', 'credit_scoring:view'],
        'GET borrower' => ['GET', '/api/credit-scoring/borrowers/1', 'credit_scoring:view'],
        'GET borrower history' => ['GET', '/api/credit-scoring/borrowers/1/history', 'credit_scoring:view'],
        'GET borrower policy flags' => ['GET', '/api/credit-scoring/borrowers/1/policy-flags', 'credit_scoring:view'],
        'GET score history' => ['GET', '/api/credit-scoring/score-history', 'credit_scoring:view'],
        'GET risk monitoring' => ['GET', '/api/credit-scoring/risk-monitoring', 'credit_scoring:view'],
        'GET scorecard config' => ['GET', '/api/credit-scoring/scorecard-config', 'credit_scoring:settings'],
        'PUT scorecard config' => ['PUT', '/api/credit-scoring/scorecard-config', 'credit_scoring:settings'],
        'GET settings' => ['GET', '/api/credit-scoring/settings', 'credit_scoring:settings'],
        'PUT settings' => ['PUT', '/api/credit-scoring/settings', 'credit_scoring:settings'],
        'POST decisions' => ['POST', '/api/credit-scoring/decisions', 'credit_scoring:override'],
    ];
}

dataset('credit scoring placeholder endpoints', creditScoringPlaceholderEndpoints());

/**
 * A user whose only role holds exactly these permissions.
 */
function creditScoringCallerHolding(string ...$permissions): User
{
    $role = Role::create(['name' => 'spec:'.implode(',', $permissions), 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    return tap(User::factory()->create(), fn (User $user) => $user->assignRole($role));
}

function creditScoringCallerInRole(string $roleName): User
{
    return tap(
        User::factory()->create(),
        fn (User $user) => $user->assignRole(Role::where('name', $roleName)->firstOrFail()),
    );
}

it('serves exactly the endpoints the frontend calls', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/credit-scoring'))
        ->flatMap(fn ($route) => collect($route->methods())
            ->reject(fn (string $method) => $method === 'HEAD')
            ->map(fn (string $method) => "{$method} /{$route->uri()}"))
        ->sort()
        ->values()
        ->all();

    expect($routes)->toBe([
        'GET /api/credit-scoring/borrowers',
        'GET /api/credit-scoring/borrowers/{borrowerId}',
        'GET /api/credit-scoring/borrowers/{borrowerId}/history',
        'GET /api/credit-scoring/borrowers/{borrowerId}/policy-flags',
        'GET /api/credit-scoring/dashboard',
        'GET /api/credit-scoring/risk-monitoring',
        'GET /api/credit-scoring/score-history',
        'GET /api/credit-scoring/scorecard-config',
        'GET /api/credit-scoring/settings',
        'POST /api/credit-scoring/decisions',
        'PUT /api/credit-scoring/scorecard-config',
        'PUT /api/credit-scoring/settings',
    ]);
});

it('answers 501 to a caller whose role holds the permission', function (string $method, string $uri, string $permission) {
    $this->actingAs(creditScoringCallerHolding($permission))
        ->json($method, $uri)
        ->assertStatus(501)
        ->assertExactJson(['message' => 'Credit Scoring is not available yet.']);
})->with('credit scoring placeholder endpoints');

it('answers 403 to a caller whose role holds only the other credit scoring permissions', function (string $method, string $uri, string $permission) {
    $others = array_values(array_diff(
        ['credit_scoring:view', 'credit_scoring:override', 'credit_scoring:settings'],
        [$permission],
    ));

    $this->actingAs(creditScoringCallerHolding('dashboard:view', ...$others))
        ->json($method, $uri)
        ->assertForbidden();
})->with('credit scoring placeholder endpoints');

it('answers 501 to the seeded admin role', function (string $method, string $uri) {
    $this->actingAs(creditScoringCallerInRole('admin'))
        ->json($method, $uri)
        ->assertStatus(501);
})->with('credit scoring placeholder endpoints');

/**
 * One user per role, so each request is made by the role it names. Every
 * seeded role is checked against the database in
 * CreditScoringRolePermissionsTest; this proves none of them reaches a route.
 */
it('answers 403 to every other seeded role', function (string $roleName) {
    $this->actingAs(creditScoringCallerInRole($roleName));

    foreach (creditScoringPlaceholderEndpoints() as [$method, $uri]) {
        expect($this->json($method, $uri)->status())->toBe(403, "{$roleName} reached {$method} {$uri}.");
    }
})->with([
    'loan_officer', 'cashier', 'collector', 'viewer', 'general_bookkeeper',
    'loan_processor', 'manager', 'bod1', 'bod2', 'bod3', 'bod4', 'bod5', 'bod6', 'bod7',
]);

it('needs a signed-in caller', function (string $method, string $uri) {
    $this->json($method, $uri)->assertUnauthorized();
})->with('credit scoring placeholder endpoints');

it('answers 404 to a borrower id that is not a number', function (string $suffix) {
    $this->actingAs(creditScoringCallerInRole('admin'))
        ->getJson("/api/credit-scoring/borrowers/abc{$suffix}")
        ->assertNotFound();
})->with(['', '/history', '/policy-flags']);
