<?php

use App\Http\Middleware\PublicApply\EnsureOriginationEnabled;
use App\Http\Middleware\PublicApply\PublicApplyNoStore;
use App\Http\Middleware\PublicApply\ResolveApplicationLink;
use App\Models\User;
use App\Providers\LoanOriginationServiceProvider;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Phase6\LoanOrigination\PublicIntake\PublicApplyHelpers;
use Tests\TestCase;

/*
 * LO-B2: the public application's routes (origination decisions 1, 2, 7 and
 * 17). Every `api/public-apply/*` route carries the public stack (no-store,
 * the server switch, the link) and its own `throttle:public-apply-*` limiter,
 * never the shared `api` one; no route takes a staff token; nothing answers
 * while LOAN_ORIGINATION_ENABLED is off; and production refuses to boot with
 * the test-code mode on.
 */
uses(TestCase::class, PublicApplyHelpers::class);

/** @return list<RoutingRoute> */
function loPubRoutes(): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/public-apply/'),
    ));
}

it('serves the public application from the routes the wizard needs', function () {
    $routes = collect(loPubRoutes())->map(fn (RoutingRoute $r) => implode('|', array_diff($r->methods(), ['HEAD'])).' '.$r->uri())->sort()->values()->all();

    expect($routes)->toBe([
        'DELETE api/public-apply/{token}/documents/{document}',
        'GET api/public-apply/{token}',
        'GET api/public-apply/{token}/draft',
        'GET api/public-apply/{token}/track',
        'POST api/public-apply/{token}/documents',
        'POST api/public-apply/{token}/estimate',
        'POST api/public-apply/{token}/otp',
        'POST api/public-apply/{token}/otp/verify',
        'POST api/public-apply/{token}/submit',
        'PUT api/public-apply/{token}/draft',
    ]);
});

it('gives every public-apply route its own public-apply limiter, never only the shared api one', function () {
    foreach (loPubRoutes() as $route) {
        $declared = array_values(array_filter($route->gatherMiddleware(), 'is_string'));
        $label = '/'.$route->uri();
        $own = array_filter($declared, fn (string $m) => str_starts_with($m, 'throttle:') && $m !== 'throttle:api');

        expect($own)->not->toBeEmpty("{$label} has no limiter of its own")
            ->and(array_filter($own, fn (string $m) => ! str_starts_with($m, 'throttle:public-apply-')))->toBe([], "{$label} uses a limiter outside public-apply-*")
            ->and($declared)->toContain(PublicApplyNoStore::class)
            ->and($declared)->toContain(EnsureOriginationEnabled::class)
            ->and($declared)->toContain(ResolveApplicationLink::class)
            ->and($declared)->not->toContain('auth:sanctum')
            ->and((string) $route->getName())->toStartWith('public-apply.');
    }

    expect(count(loPubRoutes()))->toBe(10);
});

it('registers every limiter the public-apply routes name', function () {
    $names = collect(loPubRoutes())
        ->flatMap(fn (RoutingRoute $r) => $r->gatherMiddleware())
        ->filter(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:public-apply-'))
        ->map(fn (string $m) => substr($m, strlen('throttle:')))
        ->unique()->values();

    expect($names)->not->toBeEmpty();
    foreach ($names as $name) {
        expect(RateLimiter::limiter($name))->not->toBeNull("{$name} isn't registered");
    }
});

it('exempts a request from the shared api limiter only when both its name and its path are public-apply', function () {
    $limiter = RateLimiter::limiter('api');
    $requestFor = function (string $name, string $uri): Request {
        $request = Request::create('/'.$uri, 'GET');
        $route = new RoutingRoute(['GET'], $uri, []);
        $route->name($name);
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => null);

        return $request;
    };

    expect($limiter($requestFor('public-apply.portal', 'api/public-apply/abc')))->toBeInstanceOf(Unlimited::class)
        ->and($limiter($requestFor('public-apply.probe', 'api/loans/7')))->not->toBeInstanceOf(Unlimited::class)
        ->and($limiter($requestFor('loans.show', 'api/public-apply/abc')))->not->toBeInstanceOf(Unlimited::class);
});

it('answers 404 on every public-apply route while the server switch is off, even for a real link', function () {
    $link = $this->enableIntake();
    config(['loan_origination.enabled' => false]);
    expect(loPubRoutes())->not->toBeEmpty();

    foreach (loPubRoutes() as $route) {
        $uri = '/'.str_replace(['{token}', '{document}'], [$link->token, 'abc'], $route->uri());

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $this->json($method, $uri)->assertNotFound();
        }
    }

    expect($link->fresh()->visits)->toBe(0);
});

it('answers 404 for an unknown token, and matches tokens exactly, case included', function () {
    $link = $this->enableIntake();

    $this->getJson('/api/public-apply/'.str_repeat('x', 32))->assertNotFound();
    $swapped = implode('', array_map(fn (string $c) => ctype_upper($c) ? strtolower($c) : strtoupper($c), str_split($link->token)));
    $this->getJson("/api/public-apply/{$swapped}")->assertNotFound();
    $this->getJson("/api/public-apply/{$link->token}")->assertOk();
});

it('marks every answer no-store', function () {
    $link = $this->enableIntake();

    $response = $this->getJson("/api/public-apply/{$link->token}")->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('treats a staff token exactly as no token', function () {
    $link = $this->enableIntake();
    $staff = User::factory()->create();
    $token = $staff->createToken('t')->plainTextToken;

    $this->getJson("/api/public-apply/{$link->token}/draft", ['Authorization' => "Bearer {$token}"])->assertUnauthorized()
        ->assertJsonPath('code', 'session_required');
});

it('refuses to boot in production with the test-code mode on', function () {
    $app = app();
    $previous = $app['env'];
    config(['loan_origination_intake.otp.test_codes' => true]);

    try {
        $app['env'] = 'production';
        expect(fn () => (new LoanOriginationServiceProvider($app))->boot())
            ->toThrow(RuntimeException::class, 'LOAN_ORIGINATION_OTP_TEST_CODES');

        config(['loan_origination_intake.otp.test_codes' => false]);
        (new LoanOriginationServiceProvider($app))->boot();

        $app['env'] = 'staging';
        config(['loan_origination_intake.otp.test_codes' => true]);
        (new LoanOriginationServiceProvider($app))->boot();
    } finally {
        $app['env'] = $previous;
    }

    expect(true)->toBeTrue();
});
