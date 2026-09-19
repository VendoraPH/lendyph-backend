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
use Illuminate\Testing\TestResponse;
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

    // Declaration order at routes/api.php:127, which must survive the sort:
    // "session expired" (401) and "account deactivated" (403) both outrank
    // "you owe us a new password", and a deactivated user must be turned away
    // rather than sent to a change-password screen that would let them back in.
    $gates = [CheckTokenExpiry::class, EnsureUserIsActive::class, RequirePasswordChange::class];

    $swept = 0;

    foreach ($router->getRoutes() as $route) {
        $stack = array_values(array_filter($router->gatherRouteMiddleware($route), 'is_string'));

        $gateIndexes = array_map(fn (string $gate) => array_search($gate, $stack, true), $gates);
        $bindingAt = array_search(SubstituteBindings::class, $stack, true);

        if (in_array(false, $gateIndexes, true) || $bindingAt === false) {
            continue;
        }

        $swept++;

        $label = implode('|', $route->methods()).' /'.$route->uri();
        $effective = 'effective order: '.implode(' -> ', $stack);

        expect(max($gateIndexes))->toBeLessThan(
            $bindingAt,
            "{$label}: all three post-auth gates must sort ahead of SubstituteBindings, ".
            'or a missing id 404s before they run while a live id reaches them — '.
            "which is a user-existence oracle. {$effective}",
        );

        expect($gateIndexes)->toBe(
            collect($gateIndexes)->sort()->values()->all(),
            "{$label}: the three gates must keep their declared relative order, ".
            "RequirePasswordChange last. {$effective}",
        );

        // The other half of the placement. The gates were put between the
        // throttle entries and SubstituteBindings, not in front of the meter:
        // six named limiters in AppServiceProvider branch on whether
        // $request->user() is populated, and moving a gate above `throttle:*`
        // would change which bucket an authenticated caller is metered on.
        $throttleAt = collect($stack)->search(
            fn (string $m) => str_starts_with($m, ThrottleRequests::class.':'),
        );

        if ($throttleAt !== false) {
            expect(min($gateIndexes))->toBeGreaterThan(
                $throttleAt,
                "{$label}: the gates must stay BEHIND the throttle — the limiters in ".
                "AppServiceProvider depend on the identity resolved before them. {$effective}",
            );
        }
    }

    // A loop that matched nothing would pass every assertion above.
    expect($swept)->toBeGreaterThan(
        100,
        'expected the gated routes to be swept; found '.$swept.'. If the auth '.
        'group was restructured this number moves, but zero means this spec '.
        'stopped testing anything.',
    );
});
