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
 * The third, and the same probe on the other half of the pair.
 *
 * `PATCH /users/{id}/deactivate` against an account that is already inactive
 * is the identical `isDirty() === false` no-op — 200 "User deactivated
 * successfully.", no write, no timestamp, no audit row. It outlived the pass
 * that closed reactivate for the mirror-image reason: the dataset at the top
 * of this file seeds its fixture `active` so the SUCCESS path is observable,
 * and `active` is the one status that never reaches this case.
 *
 * The token assertion is the load-bearing half. `UserController::deactivate()`
 * sweeps `$user->tokens()` unconditionally, and on an already-inactive target
 * that sweep is real work rather than a second no-op, so the 422 stops it
 * happening. Leaving those rows is the deliberate trade argued in
 * DeactivateUserRequest::after(): they are unusable — EnsureUserIsActive
 * re-reads `status` per request and 403s whichever row is presented, then
 * deletes it — while a 422 that wrote to the target would be an unaudited
 * mutation on a refused request. Asserted rather than only documented, so the
 * trade cannot be quietly reversed by someone restoring the sweep.
 */
it('refuses to deactivate an account that is already inactive, and leaves its tokens alone', function () {
    $target = ($this->makeTarget)('inactive');
    $target->createToken('stale-device');

    $before = $target->fresh()->getAttributes();

    $this->actingAs(User::where('username', 'super_admin')->first());

    $this->patchJson("/api/users/{$target->id}/deactivate")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['changes']);

    expect($target->fresh()->getAttributes())->toBe($before)
        ->and($target->tokens()->count())->toBe(1);
});

/**
 * A guard that refuses everyone is not a fix.
 *
 * The success path has to keep working and keep sweeping. The sweep did not
 * move into the request class — it stayed on the 200, where a genuine
 * transition still revokes every session the account holds in the same
 * request, which is the only case it was ever load-bearing for.
 */
it('still deactivates an account that is actually active, and still sweeps its tokens', function () {
    $target = ($this->makeTarget)('active');
    $target->createToken('phone');
    $target->createToken('laptop');

    expect($target->tokens()->count())->toBe(2);

    $this->actingAs(User::where('username', 'super_admin')->first());

    $this->patchJson("/api/users/{$target->id}/deactivate")
        ->assertOk()
        ->assertJson(['message' => 'User deactivated successfully.']);

    expect($target->fresh()->status)->toBe('inactive')
        ->and($target->tokens()->count())->toBe(0);
});

/**
 * The ordering inside DeactivateUserRequest, stated as the case that breaks if
 * it is reversed.
 *
 * Unlike reactivate, this route has a super_admin tier of its own, so the
 * already-inactive check has to sit BEHIND it. Ahead of it, a platform account
 * that happened to be inactive would answer 422 "This account is already
 * inactive." instead of the masked 404, while `PUT /users/{id}` still answered
 * the same id 404 — a pairing no ordinary account produces, so the two together
 * name the platform's account. That is the composition ReactivateUserRequest
 * describes, rebuilt one route over. Both statuses must therefore produce the
 * same 404 a never-used id produces, bodies included.
 *
 * The family-wide spec below cannot catch this on its own, because it only ever
 * sees the platform account active: with the order reversed, this spec fails
 * and that one still passes.
 */
it('masks a super_admin target as missing whatever its status, rather than answering the new 422', function () {
    $admin = User::factory()->create(['branch_id' => 1, 'status' => 'active']);
    $admin->syncRoles([Role::findByName('admin')]);

    $platform = User::where('username', 'super_admin')->first();

    $this->actingAs($admin);

    $normalise = fn (string $body, int $id) => str_replace((string) $id, '{id}', $body);

    foreach (['active', 'inactive'] as $status) {
        $platform->update(['status' => $status]);

        $refused = $this->patchJson("/api/users/{$platform->id}/deactivate");
        $missing = $this->patchJson('/api/users/99999/deactivate');

        expect($refused->status())->toBe(404, "a super_admin target that is {$status} answered {$refused->status()}")
            ->and($missing->status())->toBe(404)
            ->and($normalise($refused->getContent(), $platform->id))
            ->toBe($normalise($missing->getContent(), 99999), "a super_admin target that is {$status} answered a different 404");

        // ...and the refusal changed nothing either way.
        expect($platform->fresh()->status)->toBe($status);
    }
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
