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

use App\Models\User;
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

it('documents the one oracle these requests cannot close, in the middleware order', function (
    string $verb,
    string $template,
    array $payload,
    string $status,
) {
    // NOT a passing-grade assertion — a characterisation test of a defect that
    // is real, reported, and deliberately not fixed on this branch. If it ever
    // fails, somebody has closed the gap: read this, then change it to expect
    // two identical answers.
    //
    // Laravel sorts the gathered middleware stack by its priority list.
    // Authenticate sits at 6 and SubstituteBindings at 10, which is why an
    // unauthenticated probe is 401 for every id (spec above) — but this
    // application's three post-auth gates (CheckTokenExpiry,
    // EnsureUserIsActive, RequirePasswordChange) are not in that list at all,
    // so binding is hoisted ABOVE them. A missing id is a 404 before they run;
    // a live id reaches them and is refused with their own status. Existence
    // is the difference between the two:
    //
    //   password-change pending  ->  423 vs 404   (token survives: repeatable)
    //   account deactivated      ->  403 vs 404   (token is revoked: one shot)
    //   token idle-expired       ->  401 vs 404   (token is revoked: one shot)
    //
    // Only the first is a practical enumerator, and it needs a live token
    // belonging to real staff mid-reset. Closing it means reordering the
    // priority list in bootstrap/app.php, which moves those three gates ahead
    // of binding on EVERY route in the API, not just these five — a change
    // with its own blast radius, and its own review.
    $target = ($this->makeTarget)($status);

    $this->outsider->forceFill(['must_change_password' => true])->save();

    // A Bearer token, not actingAs(): these gates read the access token.
    $this->withToken($this->outsider->createToken('probe')->plainTextToken);

    $real = hitUserRoute($this, $verb, $template, $payload, $target->id);
    $fake = hitUserRoute($this, $verb, $template, $payload, 99999);

    expect($real->status())->toBe(423)
        ->and($fake->status())->toBe(404);
})->with('user routes');
