<?php

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\Feature\CreditScoring\Api\CreditApiFixtures;
use Tests\TestCase;

/**
 * The Credit Scoring endpoints are real: a caller holding each route's
 * permissions gets an answer (never the old 501 placeholder), and one
 * without them gets 403. Every caller here is a non-super_admin user:
 * super_admin passes every check through Gate::before and would prove
 * nothing about the permission each route checks.
 */
uses(TestCase::class, CreditApiFixtures::class);

beforeEach(function () {
    $this->pinToday();
    $this->subject = $this->scoredMember();
    $this->subjectLoan = $this->application($this->subject);
});

/**
 * Method, path (with `{member}` and `{loan}` filled in per test) and the
 * permissions the route needs, for every endpoint the frontend calls. Not
 * `GET /alerts`: alerts arrive inside risk-monitoring.
 *
 * @return array<string, array{string, string, list<string>}>
 */
function creditScoringEndpoints(): array
{
    return [
        'GET dashboard' => ['GET', '/api/credit-scoring/dashboard', ['credit_scoring:view']],
        'GET borrowers' => ['GET', '/api/credit-scoring/borrowers', ['credit_scoring:view']],
        'GET borrower' => ['GET', '/api/credit-scoring/borrowers/{member}', ['credit_scoring:view']],
        'GET borrower history' => ['GET', '/api/credit-scoring/borrowers/{member}/history', ['credit_scoring:view']],
        'GET borrower policy flags' => ['GET', '/api/credit-scoring/borrowers/{member}/policy-flags', ['credit_scoring:view']],
        'GET borrower decisions' => ['GET', '/api/credit-scoring/borrowers/{member}/decisions', ['credit_scoring:view']],
        'GET score history' => ['GET', '/api/credit-scoring/score-history', ['credit_scoring:view']],
        'GET risk monitoring' => ['GET', '/api/credit-scoring/risk-monitoring', ['credit_scoring:view']],
        'GET scorecard config' => ['GET', '/api/credit-scoring/scorecard-config', ['credit_scoring:settings']],
        'PUT scorecard config' => ['PUT', '/api/credit-scoring/scorecard-config', ['credit_scoring:settings']],
        'GET settings' => ['GET', '/api/credit-scoring/settings', ['credit_scoring:settings']],
        'PUT settings' => ['PUT', '/api/credit-scoring/settings', ['credit_scoring:settings']],
        'POST decisions' => ['POST', '/api/credit-scoring/decisions', ['credit_scoring:override']],
        'GET loan assessment' => ['GET', '/api/credit-scoring/loans/{loan}/assessment', ['credit_scoring:view', 'loans:view']],
        'PUT loan assessment' => ['PUT', '/api/credit-scoring/loans/{loan}/assessment', ['credit_scoring:view', 'loans:view', 'loans:update']],
    ];
}

dataset('credit scoring endpoints', creditScoringEndpoints());

/**
 * A user in the main branch holding only the named seeded role, so a 403 is
 * the permission check and never a branch-scoped 404.
 */
function creditScoringCallerInRole(string $roleName): User
{
    return tap(
        User::factory()->inBranches(Branch::query()->orderBy('id')->firstOrFail())->create(),
        fn (User $user) => $user->assignRole(Role::where('name', $roleName)->firstOrFail()),
    );
}

function creditScoringUri(string $uri, Borrower $member, int $loanId): string
{
    return str_replace(['{member}', '{loan}'], [(string) $member->id, (string) $loanId], $uri);
}

it('serves exactly the endpoints the frontend calls, plus the loan assessment and a member\'s decisions', function () {
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
        'GET /api/credit-scoring/borrowers/{borrowerId}/decisions',
        'GET /api/credit-scoring/borrowers/{borrowerId}/history',
        'GET /api/credit-scoring/borrowers/{borrowerId}/policy-flags',
        'GET /api/credit-scoring/dashboard',
        'GET /api/credit-scoring/loans/{loan}/assessment',
        'GET /api/credit-scoring/risk-monitoring',
        'GET /api/credit-scoring/score-history',
        'GET /api/credit-scoring/scorecard-config',
        'GET /api/credit-scoring/settings',
        'POST /api/credit-scoring/decisions',
        'PUT /api/credit-scoring/loans/{loan}/assessment',
        'PUT /api/credit-scoring/scorecard-config',
        'PUT /api/credit-scoring/settings',
    ]);
});

it('answers a caller whose role holds the permissions, never with the old 501', function (string $method, string $uri, array $permissions) {
    $response = $this->actingAs($this->caller($permissions))
        ->json($method, creditScoringUri($uri, $this->subject, $this->subjectLoan->id));

    expect($response->status())->not->toBeIn([403, 404, 500, 501]);

    if ($response->status() < 300) {
        $response->assertJsonPath('success', true)->assertJsonStructure(['success', 'data']);
    }
})->with('credit scoring endpoints');

it('answers 403 to a caller missing any one of the permissions', function (string $method, string $uri, array $permissions) {
    foreach ($permissions as $missing) {
        $held = array_values(array_diff(
            ['dashboard:view', 'credit_scoring:view', 'credit_scoring:override', 'credit_scoring:settings', 'loans:view', 'loans:update'],
            [$missing],
        ));

        $this->actingAs($this->caller($held))
            ->json($method, creditScoringUri($uri, $this->subject, $this->subjectLoan->id))
            ->assertForbidden();
    }
})->with('credit scoring endpoints');

it('answers the seeded admin and super_admin roles, never with the old 501', function (string $roleName, string $method, string $uri) {
    $status = $this->actingAs(creditScoringCallerInRole($roleName))
        ->json($method, creditScoringUri($uri, $this->subject, $this->subjectLoan->id))
        ->status();

    expect($status)->not->toBeIn([403, 404, 500, 501]);
})->with(['admin', 'super_admin'])->with('credit scoring endpoints');

/**
 * One user per role, so each request is made by the role it names. Every
 * seeded role is checked against the database in
 * CreditScoringRolePermissionsTest; this proves none of them reaches a route.
 */
it('answers 403 to every other seeded role', function (string $roleName) {
    $this->actingAs(creditScoringCallerInRole($roleName));

    foreach (creditScoringEndpoints() as [$method, $uri]) {
        $uri = creditScoringUri($uri, $this->subject, $this->subjectLoan->id);

        expect($this->json($method, $uri)->status())->toBe(403, "{$roleName} reached {$method} {$uri}.");
    }
})->with([
    'loan_officer', 'cashier', 'collector', 'viewer', 'general_bookkeeper',
    'loan_processor', 'manager', 'bod1', 'bod2', 'bod3', 'bod4', 'bod5', 'bod6', 'bod7',
]);

it('needs a signed-in caller', function (string $method, string $uri) {
    $this->json($method, creditScoringUri($uri, $this->subject, $this->subjectLoan->id))->assertUnauthorized();
})->with('credit scoring endpoints');

it('answers 404 to a borrower id that is not a number', function (string $suffix) {
    $this->actingAs(creditScoringCallerInRole('admin'))
        ->getJson("/api/credit-scoring/borrowers/abc{$suffix}")
        ->assertNotFound();
})->with(['', '/history', '/policy-flags', '/decisions']);
