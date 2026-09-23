<?php

/**
 * No route that binds `{user}` may tell a caller who is not allowed to use it
 * whether a given user id exists.
 *
 * Implicit route-model binding resolves `{user}` before authorisation runs, so
 * these endpoints answered 403 for a real id and 404 for a made-up one. Ids are
 * sequential, so that difference enumerates the whole staff list — count, live
 * ids, and by extension hiring and departures over time — from endpoints the
 * caller cannot actually invoke. Any authenticated token is enough.
 *
 * `POST /users/{user}/reset-password` was hardened first;
 * ResetPasswordEnumerationTest is the narrative version of why, and keeps the
 * specs that only make sense there (the password is not changed, the flag is
 * not armed). This file states the same property as a rule over the whole
 * route family instead, because the oracle is only closed if ALL of them agree:
 * fixing one endpoint while four siblings still answer 403 moves the probe, it
 * does not stop it. Reset-password is in the dataset too, deliberately — the
 * two files then cross-check each other, and a sixth `{user}` route is one new
 * line here rather than a new file.
 *
 * The load-bearing assertion is byte-identical response BODIES, not just equal
 * status codes: 404 with a different message is still an oracle.
 */

use App\Http\Middleware\CheckTokenExpiry;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RequirePasswordChange;
use App\Models\User;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\ExpectationFailedException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Every route in the group, with a payload its authorised caller would accept
 * and the target status that makes a SUCCESSFUL call observable — reactivating
 * an already-active user changes nothing, which would make "the refusal wrote
 * nothing" pass for the wrong reason.
 */
dataset('user routes', [
    'GET /users/{user}' => ['getJson', '/api/users/{id}', [], 'active'],
    'PUT /users/{user}' => ['putJson', '/api/users/{id}', ['first_name' => 'Renamed'], 'active'],
    'PATCH /users/{user}/deactivate' => ['patchJson', '/api/users/{id}/deactivate', [], 'active'],
    'PATCH /users/{user}/reactivate' => ['patchJson', '/api/users/{id}/reactivate', [], 'inactive'],
    'POST /users/{user}/reset-password' => ['postJson', '/api/users/{id}/reset-password', [
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ], 'active'],
]);

/**
 * `getJson()` takes HEADERS where the others take a body, which is why the GET
 * row above carries an empty payload and must keep carrying one.
 */
function hitUserRoute(TestCase $test, string $verb, string $template, array $payload, int|string $id): TestResponse
{
    return $test->{$verb}(str_replace('{id}', (string) $id, $template), $payload);
}

beforeEach(function () {
    // A collector: a real, active, fully authenticated member of staff who
    // holds none of users:view / users:update / users:delete /
    // users:reset_password.
    $this->outsider = User::factory()->create(['branch_id' => 1, 'status' => 'active']);
    $this->outsider->syncRoles([Role::findByName('collector')]);

    $this->makeTarget = fn (string $status) => User::factory()->create([
        'branch_id' => 1,
        'status' => $status,
    ]);

    // Assert the shape deployments actually serve. With APP_DEBUG on — the
    // default under phpunit.xml — Laravel appends `exception`, `file`, `line`
    // and a full stack trace to every error body, and those differ between a
    // 404 raised by SubstituteBindings and one raised in failedAuthorization().
    // That difference is a debug-only artefact and is not what a caller sees,
    // but comparing bodies with it present would compare stack frames instead
    // of the thing under test.
    config(['app.debug' => false]);
});

it('answers the same id identically whether or not it exists', function (
    string $verb,
    string $template,
    array $payload,
    string $status,
) {
    // The exact definition of "indistinguishable": hold the URL constant and
    // vary only whether the row is there. Comparing two DIFFERENT ids would be
    // weaker — the 404 body echoes the id from the URL, which is the caller's
    // own input, so those always differ for uninteresting reasons.
    $target = ($this->makeTarget)($status);
    $id = $target->id;

    $this->actingAs($this->outsider);

    $whileItExists = hitUserRoute($this, $verb, $template, $payload, $id);

    $target->delete();

    $whileItDoesNot = hitUserRoute($this, $verb, $template, $payload, $id);

    expect($whileItExists->status())->toBe(404)
        ->and($whileItDoesNot->status())->toBe(404)
        ->and($whileItExists->json())->toEqual($whileItDoesNot->json())
        ->and($whileItExists->getContent())->toBe($whileItDoesNot->getContent());
})->with('user routes');

it('does not distinguish a real id from a never-used one', function (
    string $verb,
    string $template,
    array $payload,
    string $status,
) {
    // The same property stated the other way round, tolerating only the echoed
    // id: both answers must be the generic not-found shape, not 403 vs 404.
    $target = ($this->makeTarget)($status);

    $this->actingAs($this->outsider);

    $real = hitUserRoute($this, $verb, $template, $payload, $target->id);
    $fake = hitUserRoute($this, $verb, $template, $payload, 99999);

    $normalise = fn (string $body, int $id) => str_replace((string) $id, '{id}', $body);

    expect($real->status())->toBe($fake->status())
        ->and($normalise($real->getContent(), $target->id))
        ->toBe($normalise($fake->getContent(), 99999));
})->with('user routes');

it('does not perform the action it refuses to acknowledge', function (
    string $verb,
    string $template,
    array $payload,
    string $status,
) {
    // A 404 that still did the work would be the worse bug of the two. Compare
    // the whole row rather than the one column each route touches, so this
    // keeps holding if an action starts writing something else.
    $target = ($this->makeTarget)($status);
    $before = $target->fresh()->getAttributes();

    $this->actingAs($this->outsider);

    hitUserRoute($this, $verb, $template, $payload, $target->id)->assertNotFound();

    expect($target->fresh()->getAttributes())->toBe($before);
})->with('user routes');

it('still serves an authorised caller', function (
    string $verb,
    string $template,
    array $payload,
    string $status,
) {
    // The carve-out must not cost the feature. A fix that 404s everyone would
    // pass every assertion above.
    $target = ($this->makeTarget)($status);
    $admin = User::where('username', 'super_admin')->first();

    $this->actingAs($admin);

    hitUserRoute($this, $verb, $template, $payload, $target->id)->assertOk();
})->with('user routes');

it('still tells an authorised caller when an id does not exist', function (
    string $verb,
    string $template,
    array $payload,
) {
    // Non-enumeration is owed to people who may not ask, not to people who may.
    $admin = User::where('username', 'super_admin')->first();

    $this->actingAs($admin);

    hitUserRoute($this, $verb, $template, $payload, 99999)->assertNotFound();
})->with('user routes');

it('does not leak existence through the unauthenticated path either', function (
    string $verb,
    string $template,
    array $payload,
    string $status,
) {
    // No token at all: 401 before anything is resolved, for both ids.
    $target = ($this->makeTarget)($status);

    $real = hitUserRoute($this, $verb, $template, $payload, $target->id);
    $fake = hitUserRoute($this, $verb, $template, $payload, 99999);

    expect($real->status())->toBe(401)
        ->and($fake->status())->toBe(401)
        ->and($real->getContent())->toBe($fake->getContent());
})->with('user routes');

it('answers a locked caller identically whether or not the id exists', function (
    string $verb,
    string $template,
    array $payload,
    string $status,
) {
    // This used to be a characterisation test of a defect, asserting 423 vs 404.
    // The defect is fixed; this is now the property.
    //
    // Laravel sorts the gathered middleware stack by its priority list, and
    // this application's three post-auth gates (CheckTokenExpiry,
    // EnsureUserIsActive, RequirePasswordChange) were absent from it. Their
    // absence is what made them run AFTER route-model binding — binding was
    // never hoisted over them, which is how the previous version of this
    // comment put it and it was wrong. The `api` group SUPPLIES
    // SubstituteBindings and a group's middleware is gathered outermost, while
    // the gates are declared on the nested group at routes/api.php:127, so
    // binding was in front from the start. Being unlisted is what made that
    // position unrecoverable: an unlisted middleware keeps the index it was
    // gathered at, and listed middleware step over it.
    //
    // The consequence was that a missing id was a 404 before the gates ran
    // while a live id reached them and was refused with their own status, so
    // existence was the difference between the two answers:
    //
    //   password-change pending  ->  423 vs 404   (token survives: repeatable)
    //   account deactivated      ->  403 vs 404   (token is revoked: one shot)
    //   token idle-expired       ->  401 vs 404   (token is revoked: one shot)
    //
    // Only the first was a practical enumerator — RequirePasswordChange does
    // not revoke the token, so the probe could walk the whole sequential id
    // range on one live token belonging to staff mid-reset. bootstrap/app.php
    // now names all three in the priority list, immediately ahead of
    // SubstituteBindings, so the gate answers first and says the same thing for
    // every id. The spec below pins the ordering itself.
    $target = ($this->makeTarget)($status);

    $this->outsider->forceFill(['must_change_password' => true])->save();

    // A Bearer token, not actingAs(): these gates read the access token.
    $this->withToken($this->outsider->createToken('probe')->plainTextToken);

    $real = hitUserRoute($this, $verb, $template, $payload, $target->id);
    $fake = hitUserRoute($this, $verb, $template, $payload, 99999);

    // Byte-identical bodies, not just equal statuses — the 423 payload is
    // static (message, `code`, `must_change_password`) and carries nothing
    // drawn from the target, so there is nothing legitimate for it to differ
    // on. A 423 that started echoing the id would re-open the oracle at the
    // same URL.
    expect($real->status())->toBe(423)
        ->and($fake->status())->toBe(423)
        ->and($real->getContent())->toBe($fake->getContent());
})->with('user routes');

/**
 * Every index in a gathered stack that holds a throttle entry.
 *
 * The plural is the whole point. bootstrap/app.php prepends `throttle:api` to
 * the api group, so EVERY api route carries one — and seventeen of the swept
 * routes carry a second or a third on top of it (`throttle:public-registration`,
 * `throttle:registration-uploads`, `throttle:exports`, and everything under
 * `imports.*`, three of which stack all three). The assertion that uses this
 * used to locate "the" throttle with Collection::search(), which returns the
 * FIRST match, so on every one of those routes a gate that had slipped past the
 * SECOND entry still counted as being behind the throttle. It was checking the
 * wrong entry, not a missing one.
 *
 * @param  list<string>  $stack
 * @return list<int>
 */
function throttleIndexesIn(array $stack): array
{
    $indexes = [];

    foreach ($stack as $index => $middleware) {
        if (str_starts_with($middleware, ThrottleRequests::class.':')) {
            $indexes[] = $index;
        }
    }

    return $indexes;
}

/**
 * The ordering invariant, stated over ONE gathered stack.
 *
 * Lifted out of the sweep below so it can be pointed at a stack the router will
 * never build. A sweep over real routes can only ever show that today's routes
 * are fine; it cannot show that the assertions would NOTICE one that was not,
 * and both holes closed here were holes of exactly that kind — green every run,
 * for the same reason. SortedMiddleware will not produce a broken stack on
 * demand, so the only way to test the test is to hand it one. The harness under
 * the sweep does that, and this signature is what makes it possible.
 *
 * @param  list<string>  $stack  a gathered, sorted stack as gatherRouteMiddleware() returns it
 * @param  string  $label  route identifier, for the failure message
 * @return bool whether the route was swept; false means it carries no gate at all
 */
function assertPostAuthGateOrder(array $stack, string $label): bool
{
    // Declaration order at routes/api.php:127, which must survive the sort:
    // "session expired" (401) and "account deactivated" (403) both outrank
    // "you owe us a new password", and a deactivated user must be turned away
    // rather than sent to a change-password screen that would let them back in.
    $gates = [CheckTokenExpiry::class, EnsureUserIsActive::class, RequirePasswordChange::class];

    // The gates this route ACTUALLY carries, in declaration order.
    //
    // This used to require all three and skip the route otherwise, so a route
    // that declared only one of them was not swept and nothing said so — a lone
    // RequirePasswordChange behind SubstituteBindings is the same user-existence
    // oracle on that one route. Nothing has a partial set today: all 215 gated
    // routes carry all three, because every declaration in routes/api.php names
    // them together. This is a guard against the next one-line route, not a live
    // defect. Only a route with NO gate at all is skipped now.
    $gateIndexes = [];

    foreach ($gates as $gate) {
        $at = array_search($gate, $stack, true);

        if ($at !== false) {
            $gateIndexes[] = $at;
        }
    }

    if ($gateIndexes === []) {
        return false;
    }

    $effective = 'effective order: '.implode(' -> ', $stack);

    // Conditional because a gated route need not bind anything. It is still
    // swept for the two assertions below — which is strictly more than the old
    // loop did, since it skipped such a route outright.
    $bindingAt = array_search(SubstituteBindings::class, $stack, true);

    if ($bindingAt !== false) {
        expect(max($gateIndexes))->toBeLessThan(
            $bindingAt,
            "{$label}: every post-auth gate on this route must sort ahead of ".
            'SubstituteBindings, or a missing id 404s before they run while a live '.
            "id reaches them — which is a user-existence oracle. {$effective}",
        );
    }

    expect($gateIndexes)->toBe(
        collect($gateIndexes)->sort()->values()->all(),
        "{$label}: the gates must keep their declared relative order, ".
        "RequirePasswordChange last. {$effective}",
    );

    // The other half of the placement. The gates were put between the throttle
    // entries and SubstituteBindings, not in front of the meter: six named
    // limiters in AppServiceProvider branch on whether $request->user() is
    // populated, and moving a gate above `throttle:*` would change which bucket
    // an authenticated caller is metered on.
    //
    // Against the LAST throttle entry, not the first: on a route carrying two or
    // three of them, being behind `throttle:api` says nothing whatsoever about
    // being behind `throttle:imports`.
    $throttleIndexes = throttleIndexesIn($stack);

    if ($throttleIndexes !== []) {
        expect(min($gateIndexes))->toBeGreaterThan(
            max($throttleIndexes),
            "{$label}: the gates must stay BEHIND every throttle entry — the limiters ".
            "in AppServiceProvider depend on the identity resolved before them. {$effective}",
        );
    }

    return true;
}

/**
 * The ordering invariant itself, read off the stack the framework actually
 * builds.
 *
 * Asserting the declared order in routes/api.php would be worthless: it was
 * already "correct" throughout the bug, and Router::resolveMiddleware() running
 * the stack through SortedMiddleware is what undid it. So this reads the
 * EFFECTIVE stack via gatherRouteMiddleware().
 *
 * It sweeps every registered route rather than the five in the dataset on
 * purpose. The priority list is global — naming a middleware there moves it on
 * every route that carries it, which here is 215 routes, 117 of them bound.
 * That blast radius is the reason this change needed its own review, and a
 * sweep is the only assertion that covers it.
 */
it('runs the post-auth gates before route-model binding on every route that has them', function () {
    $router = app('router');

    $swept = 0;
    $sweptCarryingSeveralThrottles = 0;

    foreach ($router->getRoutes() as $route) {
        $stack = array_values(array_filter($router->gatherRouteMiddleware($route), 'is_string'));

        $label = implode('|', $route->methods()).' /'.$route->uri();

        if (! assertPostAuthGateOrder($stack, $label)) {
            continue;
        }

        $swept++;

        if (count(throttleIndexesIn($stack)) > 1) {
            $sweptCarryingSeveralThrottles++;
        }
    }

    // A loop that matched nothing would pass every assertion above.
    expect($swept)->toBeGreaterThan(
        100,
        'expected the gated routes to be swept; found '.$swept.'. If the auth '.
        'group was restructured this number moves, but zero means this spec '.
        'stopped testing anything.',
    );

    // The throttle assertion is only capable of catching anything new on a route
    // carrying more than one throttle entry, which is the shape it used to be
    // blind on. Seventeen swept routes are shaped like that today. This is not
    // the coverage for that fix — the synthetic case below is, and no routing
    // change can take it away — it records that the live fleet still exercises
    // the shape. If a legitimate routing change takes this to zero, relax THIS
    // guard; do not touch that spec.
    expect($sweptCarryingSeveralThrottles)->toBeGreaterThan(
        0,
        'no swept route carries more than one throttle entry any more, so the '.
        'sweep no longer distinguishes the first throttle from the last.',
    );
});

/**
 * ---------------------------------------------------------------------------
 * The harness: specs that test the assertion instead of the routes.
 * ---------------------------------------------------------------------------
 *
 * The sweep above was green on every run while carrying two defects, and it was
 * green for a reason that has nothing to do with luck: every route it looks at
 * is correctly ordered, so nothing it asserts is ever exercised in the negative.
 * A passing sweep says "the routes are fine". It does not say "and I would have
 * told you otherwise".
 *
 * These four specs say the second thing. Each hands assertPostAuthGateOrder() a
 * stack with exactly one deliberate defect and requires it to object, on the
 * right assertion. The stacks are REAL gathered stacks with a single middleware
 * moved or dropped — not hand-written arrays — so they stay honest if the
 * middleware on those routes change, and each mutation is chosen so that only
 * one of the three assertions can fire. That last part matters: a synthetic case
 * that trips two assertions cannot tell you which one is doing the work.
 */

/**
 * The gathered, sorted stack of a real route — the same idiom
 * PublicRegistrationRateLimitTest resolves its stacks with.
 *
 * @return list<string>
 */
function gateStackFor(string $method, string $uri): array
{
    $router = app('router');

    $route = collect($router->getRoutes())->first(
        fn (Route $candidate) => $candidate->uri() === $uri
            && in_array($method, $candidate->methods(), true),
    );

    expect($route)->toBeInstanceOf(Route::class, "no route is registered for {$method} /{$uri}");

    return array_values(array_filter($router->gatherRouteMiddleware($route), 'is_string'));
}

/**
 * $stack with $middleware lifted out and re-inserted immediately after $anchor.
 *
 * @param  list<string>  $stack
 * @return list<string>
 */
function gateStackWithMovedAfter(array $stack, string $middleware, string $anchor): array
{
    $rest = array_values(array_filter($stack, fn (string $entry) => $entry !== $middleware));

    $anchorAt = array_search($anchor, $rest, true);

    expect($anchorAt)->toBeInt("cannot build the synthetic stack: {$anchor} is not on it");

    array_splice($rest, $anchorAt + 1, 0, [$middleware]);

    return $rest;
}

/**
 * @param  list<string>  $stack
 * @return list<string>
 */
function gateStackWithout(array $stack, string ...$middleware): array
{
    return array_values(array_filter(
        $stack,
        fn (string $entry) => ! in_array($entry, $middleware, true),
    ));
}

/**
 * Run the invariant and hand back its complaint instead of failing the test.
 *
 * Only an assertion failure counts as a complaint. Anything else — a TypeError,
 * a ValueError out of max([]) — propagates, so a helper that crashed cannot be
 * mistaken for one that objected.
 *
 * @param  list<string>  $stack
 */
function gateOrderFailure(array $stack): ?string
{
    try {
        assertPostAuthGateOrder($stack, 'synthetic');

        return null;
    } catch (ExpectationFailedException $failed) {
        return $failed->getMessage();
    }
}

/**
 * Assert the invariant REJECTS $stack, and rejects it on the intended
 * assertion rather than a neighbouring one.
 *
 * @param  list<string>  $stack
 */
function expectGateOrderRejects(array $stack, string $onTheAssertionSaying, string $context): void
{
    $failure = gateOrderFailure($stack);

    // A positive expectation on purpose. Pest renders the message of a NEGATED
    // one through a shortening exporter, so `->not->toBeNull($why)` cuts this
    // down to a few characters — and this is the one message anybody reads
    // when the harness is doing its job.
    expect($failure)->toBeString(
        "{$context}: the invariant ACCEPTED this stack. effective order: ".
        implode(' -> ', $stack),
    );

    // toContain takes no custom message, and here that is an advantage: its own
    // failure prints the complaint that DID come back, which is what you need
    // to see when a mutation trips the wrong assertion.
    expect((string) $failure)->toContain($onTheAssertionSaying);
}

it('holds on the unmutated stacks every synthetic case below is built from', function () {
    // The control, and it is not ceremony. A stack that already fails can only
    // be "rejected" again, so a mutation built on one proves nothing; and an
    // empty stack for POST /api/auth/login would make "zero gates is skipped"
    // below true of nothing at all.
    foreach ([['POST', 'api/imports'], ['GET', 'api/users/{user}'], ['POST', 'api/auth/login']] as [$method, $uri]) {
        $stack = gateStackFor($method, $uri);

        expect(count($stack))->toBeGreaterThan(0, "{$method} /{$uri} gathered an empty stack")
            ->and(gateOrderFailure($stack))->toBeNull("{$method} /{$uri} does not hold today");
    }

    // ...and the two gated ones really are swept, so "rejected" below can never
    // be confused with "skipped".
    expect(assertPostAuthGateOrder(gateStackFor('POST', 'api/imports'), 'control'))->toBeTrue()
        ->and(assertPostAuthGateOrder(gateStackFor('GET', 'api/users/{user}'), 'control'))->toBeTrue();
});

it('rejects a stack with a gate wedged between two throttle entries', function () {
    // Weakness A, and it was reachable today rather than theoretical:
    // POST /api/imports really does carry `throttle:api` and then
    // `throttle:imports`, and seventeen swept routes are shaped like it.
    $real = gateStackFor('POST', 'api/imports');

    $throttles = array_map(fn (int $index) => $real[$index], throttleIndexesIn($real));

    expect($throttles)->toHaveCount(
        2,
        'this case needs a route carrying two throttle entries; POST /api/imports '.
        'no longer does, so pick another from throttleIndexesIn()',
    );

    // One move. CheckTokenExpiry now runs between the two meters. Nothing else
    // changes: the three gates keep their declared relative order and all three
    // still sort ahead of SubstituteBindings, so the throttle assertion is the
    // only one that CAN fire.
    $wedged = gateStackWithMovedAfter($real, CheckTokenExpiry::class, $throttles[0]);

    $firstThrottleAt = collect($wedged)->search(
        fn (string $entry) => str_starts_with($entry, ThrottleRequests::class.':'),
    );
    $lastThrottleAt = max(throttleIndexesIn($wedged));
    $wedgedGateAt = array_search(CheckTokenExpiry::class, $wedged, true);

    // The blind spot, stated as an assertion rather than as prose: this gate IS
    // behind the first throttle entry — which is all the old Collection::search()
    // version ever checked, so it passed — and is NOT behind the last one.
    expect($wedgedGateAt)->toBeGreaterThan(
        $firstThrottleAt,
        'the mutation no longer reproduces the blind spot: the gate must sit '.
        'AFTER the first throttle entry, or the old assertion would have caught it too',
    )->and($wedgedGateAt)->toBeLessThan($lastThrottleAt);

    expectGateOrderRejects(
        $wedged,
        'must stay BEHIND every throttle entry',
        'a gate between two throttle entries',
    );

    // And the case the old assertion DID catch is still caught, so this is a
    // widening rather than a swap: a gate hoisted in front of the whole meter
    // means the limiters in AppServiceProvider meter an authenticated caller on
    // the anonymous bucket.
    expectGateOrderRejects(
        gateStackWithMovedAfter($real, CheckTokenExpiry::class, $real[0]),
        'must stay BEHIND every throttle entry',
        'a gate hoisted in front of every throttle entry',
    );
});

it('sweeps a route that carries only some of the gates instead of skipping it', function () {
    // Weakness B. Theoretical, and verified as theoretical: no route has a
    // partial set today — all 215 gated routes carry all three, because every
    // declaration in routes/api.php names them together. But the loop used to
    // skip anything short of all three, so the day one route declares a single
    // gate it silently leaves the sweep, and the sweep still reports green.
    $real = gateStackFor('GET', 'api/users/{user}');

    // A route carrying RequirePasswordChange on its own, correctly placed.
    $lone = gateStackWithout($real, CheckTokenExpiry::class, EnsureUserIsActive::class);

    expect(assertPostAuthGateOrder($lone, 'synthetic'))->toBeTrue(
        'a route carrying one of the three gates must be swept, not skipped',
    )->and(gateOrderFailure($lone))->toBeNull(
        'a correctly ordered partial set must be swept AND pass',
    );

    // ...and that same lone gate behind the binding is the original oracle,
    // reduced to one route. The old loop walked past this stack without a word.
    expectGateOrderRejects(
        gateStackWithMovedAfter($lone, RequirePasswordChange::class, SubstituteBindings::class),
        'must sort ahead of SubstituteBindings',
        'a lone gate behind SubstituteBindings',
    );

    // Two of the three in the wrong relative order is caught as well — a
    // deactivated account must be turned away, not handed a change-password
    // screen that lets it back in.
    expectGateOrderRejects(
        gateStackWithMovedAfter(
            gateStackWithout($real, EnsureUserIsActive::class),
            CheckTokenExpiry::class,
            RequirePasswordChange::class,
        ),
        'declared relative order',
        'a partial set in the wrong relative order',
    );

    // Zero gates is the only thing that may be skipped. POST /api/auth/login is
    // a real route outside the auth group and carries none of the three.
    expect(assertPostAuthGateOrder(gateStackFor('POST', 'api/auth/login'), 'synthetic'))
        ->toBeFalse('a route with no gate at all has nothing to assert and must be skipped');
});

it('rejects a stack with a gate behind SubstituteBindings', function () {
    // The property the sweep has always existed for, now proved to be catchable
    // rather than merely asserted. This is the shape the application actually
    // shipped before bootstrap/app.php named the three gates in the priority
    // list: binding resolves {user} first, so a missing id 404s while a live one
    // reaches RequirePasswordChange and comes back 423.
    $real = gateStackFor('GET', 'api/users/{user}');

    // Moved last of the three, so the gates keep their relative order and the
    // throttle assertion still holds — only the binding assertion can fire.
    expectGateOrderRejects(
        gateStackWithMovedAfter($real, RequirePasswordChange::class, SubstituteBindings::class),
        'must sort ahead of SubstituteBindings',
        'the last gate behind SubstituteBindings',
    );

    // The pre-fix SHAPE: all three gates behind binding, still in declared
    // order, so once again the binding assertion is the only one that can object.
    $allThreeBehind = $real;
    $anchor = SubstituteBindings::class;

    foreach ([CheckTokenExpiry::class, EnsureUserIsActive::class, RequirePasswordChange::class] as $gate) {
        $allThreeBehind = gateStackWithMovedAfter($allThreeBehind, $gate, $anchor);
        $anchor = $gate;
    }

    expectGateOrderRejects(
        $allThreeBehind,
        'must sort ahead of SubstituteBindings',
        'all three gates behind SubstituteBindings',
    );
});

/**
 * The empty-body `PUT /users/{id}` probe.
 *
 * Every rule in UpdateUserRequest is `sometimes` (and `mobile_number` is
 * `nullable` without `required`), so `{}` validated, reached the `after()`
 * hook, and returned 200 with the full UserResource while leaving the row
 * byte-identical: `update([])` is `fill([])->save()`, `isDirty()` is false, so
 * there is no `performUpdate()`, no `updated_at` and no audit row.
 *
 * That is worse than an existence oracle. It is a free, invisible READ of a
 * record, and the read is not what the endpoint is authorised for.
 */
it('does not hand an empty-body PUT the record it refuses to GET', function () {
    // `users:view` and `users:update` are separate permissions and
    // UpdateUserRequest::authorize() checks only the second. No seeded role
    // holds one without the other, but roles are rows an administrator creates
    // through the UI, so the split is reachable by configuration alone.
    $editor = User::factory()->create(['branch_id' => 1, 'status' => 'active']);
    $editor->syncRoles([
        tap(Role::create(['name' => 'user_editor', 'guard_name' => 'web']))
            ->syncPermissions(['users:update']),
    ]);

    $target = ($this->makeTarget)('active');
    $target->syncRoles([Role::findByName('cashier')]);

    $this->actingAs($editor);

    // The endpoint they may not read with says the record is not there...
    $this->getJson("/api/users/{$target->id}")->assertNotFound();

    // ...so the endpoint they MAY write with must not read it out for them.
    //
    // This asserted 422 when it was written: the no-op guard starved the read
    // without removing the caller's right to be here. Security review pointed
    // out the rest of it — a one-key body like {"branch_id": 3} answers 422
    // when the guess is right and 200 when it is wrong, so the record was still
    // readable a column at a time. UpdateUserRequest::authorize() now requires
    // `users:view` as well, which refuses the caller outright and closes the
    // guessing game with it. 404, and the same 404 a missing id gives.
    $response = $this->putJson("/api/users/{$target->id}", []);

    $body = $response->getContent();

    expect($response->status())->toBe(404)
        ->and($response->json('data'))->toBeNull()
        ->and($body)->not->toContain($target->email)
        ->and($body)->not->toContain($target->username)
        ->and($body)->not->toContain('cashier');

    // And the field-equality probe is gone with it: a correct guess is no
    // longer distinguishable from a wrong one, because neither is answered.
    $correctGuess = $this->putJson("/api/users/{$target->id}", ['branch_id' => $target->branch_id]);
    $wrongGuess = $this->putJson("/api/users/{$target->id}", ['branch_id' => 1]);

    expect($correctGuess->getContent())->toBe($wrongGuess->getContent());
});

it('refuses a PUT that would change nothing, and writes nothing either way', function () {
    // Same whole-row comparison as the refusal spec above, aimed at the write
    // path instead: a 422 must be as inert as the 200 it replaced.
    $target = ($this->makeTarget)('active');
    $before = $target->fresh()->getAttributes();

    $this->actingAs(User::where('username', 'super_admin')->first());

    $this->putJson("/api/users/{$target->id}", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['changes']);

    expect($target->fresh()->getAttributes())->toBe($before);
});

it('refuses a PUT that only echoes the record back', function () {
    // The user form posts the whole record, so this is the shape a human
    // produces by opening the edit screen and pressing Save without touching
    // anything. It is refused, and that is a deliberate trade: the alternative
    // is a 200 that writes nothing, which is exactly the probe being closed.
    // Repeating the role the target already holds still is not a role EDIT —
    // UpdateUserRequest draws that line separately — it just is not a change.
    $target = ($this->makeTarget)('active');
    $target->syncRoles([Role::findByName('viewer')]);

    $this->actingAs(User::where('username', 'super_admin')->first());

    $this->putJson("/api/users/{$target->id}", [
        'first_name' => $target->first_name,
        'last_name' => $target->last_name,
        'username' => $target->username,
        'email' => $target->email,
        'mobile_number' => $target->mobile_number,
        'branch_id' => $target->branch_id,
        'role' => 'viewer',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['changes']);
});

it('still accepts a PUT that changes exactly one field', function () {
    // The no-op guard must cost nothing real. `branch_id` is the sharp case:
    // it arrives from JSON as whatever the client sent, and `isDirty()` is what
    // decides — "3" against an integer 3 is not a change, 2 against 1 is.
    $target = ($this->makeTarget)('active');
    $target->syncRoles([Role::findByName('viewer')]);

    $this->actingAs(User::where('username', 'super_admin')->first());

    $this->putJson("/api/users/{$target->id}", [
        'first_name' => 'Renamed',
        'last_name' => $target->last_name,
        'username' => $target->username,
        'email' => $target->email,
        'role' => 'viewer',
    ])->assertOk();

    expect($target->fresh()->first_name)->toBe('Renamed');
});

/**
 * The second free probe in the family.
 *
 * `PATCH /users/{id}/reactivate` against an account that is already active is
 * the same `isDirty() === false` no-op: a 200 saying "User reactivated
 * successfully." with no write, no timestamp and no audit row. This route has
 * no super_admin tier at all (ReactivateUserRequest says so deliberately), and
 * the dataset at the top of this file seeds its fixture `inactive` precisely so
 * the success path is observable — which left this case with no coverage.
 *
 * After the change every 200 from this route corresponds to a real state
 * change, which is what the audit trail assumes.
 */
it('refuses to reactivate an account that is already active', function () {
    $target = ($this->makeTarget)('active');
    $before = $target->fresh()->getAttributes();

    $this->actingAs(User::where('username', 'super_admin')->first());

    $this->patchJson("/api/users/{$target->id}/reactivate")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['changes']);

    expect($target->fresh()->getAttributes())->toBe($before);
});

/**
 * The super_admin boundary, stated across the whole family rather than one
 * route at a time.
 *
 * These three refusals used to be 422s whose copy named the target — "Only a
 * super_admin can edit a super_admin account." That is a sharper answer than
 * plain enumeration: one request per id and the reply says not just "this id is
 * live" but "this id is the platform's". They are now the binding's own 404.
 *
 * Swept together because agreement is the property: masking `PUT` while
 * `deactivate` still answered 422 would have moved the probe to the next URL,
 * not stopped it. UserManagementTest asserts each one individually in its
 * role-boundary context; this is the rule they have to obey as a set.
 */
it('hides a super_admin target behind the same 404 on every route that guards it', function () {
    $admin = User::factory()->create(['branch_id' => 1, 'status' => 'active']);
    $admin->syncRoles([Role::findByName('admin')]);

    $platform = User::where('username', 'super_admin')->first();

    $this->actingAs($admin);

    $guarded = [
        'PUT /users/{user}' => ['putJson', '/api/users/{id}', ['email' => 'attacker@evil.test']],
        'PATCH /users/{user}/deactivate' => ['patchJson', '/api/users/{id}/deactivate', []],
        'POST /users/{user}/reset-password' => ['postJson', '/api/users/{id}/reset-password', [
            'password' => 'pwned12345',
            'password_confirmation' => 'pwned12345',
        ]],
    ];

    $normalise = fn (string $body, int $id) => str_replace((string) $id, '{id}', $body);

    foreach ($guarded as $label => [$verb, $template, $payload]) {
        $refused = hitUserRoute($this, $verb, $template, $payload, $platform->id);
        $missing = hitUserRoute($this, $verb, $template, $payload, 99999);

        expect($refused->status())->toBe(404, "{$label} did not mask the super_admin target")
            ->and($missing->status())->toBe(404)
            ->and($normalise($refused->getContent(), $platform->id))
            ->toBe($normalise($missing->getContent(), 99999), "{$label} answered a different 404");
    }

    // ...and none of it landed.
    expect($platform->fresh()->email)->not->toBe('attacker@evil.test')
        ->and($platform->fresh()->status)->toBe('active');
});

/**
 * Raised in security review: the masking echoed the RESOLVED model's key while
 * Laravel's own binding miss echoes the raw URL segment, so a leading zero told
 * you whether the id was live.
 *
 *     GET /api/users/007   user 7 exists  ->  "... [App\Models\User] 7"
 *     GET /api/users/007   user 7 missing ->  "... [App\Models\User] 007"
 *
 * No `users:*` permission needed, repeatable, unaudited, walks the whole id
 * range. Reproduced against a real server before the fix.
 *
 * Note what this asserts and why it is not "two probes, identical bodies": two
 * different ids cannot produce identical bodies, because each correctly echoes
 * its own segment. The oracle was that a LIVE id echoed something OTHER than
 * what was sent. So that is the assertion — and it is why the `$normalise`
 * helper used elsewhere in this file must not be applied here: normalising the
 * id out is exactly what hid this.
 */
it('echoes the id the caller sent, not the one the database resolved', function () {
    $target = ($this->makeTarget)('active');

    $this->actingAs($this->outsider);

    foreach (["0{$target->id}", "00{$target->id}"] as $padded) {
        $response = $this->getJson("/api/users/{$padded}");

        expect($response->status())->toBe(404);
        expect($response->json('message'))
            ->toContain($padded)
            ->and($response->json('message'))
            ->not->toBe("No query results for model [App\\Models\\User] {$target->id}");
    }
});

/**
 * Raised in the same review: this route has no super_admin tier of its own, so
 * a 422 here against an id that `update` answers 404 for identifies the
 * super_admin outright. The guard in ReactivateUserRequest::after() closes it;
 * this pins the property rather than the implementation.
 */
it('does not let reactivate identify the account the other routes hide', function () {
    $superAdmin = User::where('username', 'super_admin')->firstOrFail();
    $admin = ($this->makeTarget)('active');
    $admin->syncRoles(['admin']);

    $this->actingAs($admin);

    $onSuperAdmin = $this->patchJson("/api/users/{$superAdmin->id}/reactivate");
    $onMissing = $this->patchJson('/api/users/99999/reactivate');

    expect($onSuperAdmin->status())->toBe(404)
        ->and($onMissing->status())->toBe(404);

    // Byte-identical apart from the id each one echoes.
    $normaliseId = fn (string $body, int|string $id) => str_replace((string) $id, '{id}', $body);

    expect($normaliseId($onSuperAdmin->getContent(), $superAdmin->id))
        ->toBe($normaliseId($onMissing->getContent(), 99999));
});
