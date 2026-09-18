<?php

use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

uses(TestCase::class);

/**
 * POST /api/users/{user}/reset-password must not tell a caller who is not
 * allowed to use it whether a given user id exists.
 *
 * Implicit route-model binding resolves `{user}` before the FormRequest is
 * authorised, so the endpoint used to answer 403 for a real id and 404 for a
 * made-up one. Ids are sequential, so that difference enumerates the whole
 * staff list from an endpoint the caller cannot actually invoke.
 */
beforeEach(function () {
    // A collector: a real, active, fully authenticated member of staff who
    // simply does not hold `users:reset_password`.
    $this->outsider = User::factory()->create(['branch_id' => 1, 'status' => 'active']);
    $this->outsider->syncRoles([Role::findByName('collector')]);

    $this->target = User::factory()->create(['branch_id' => 1, 'status' => 'active']);

    $this->payload = ['password' => 'newpassword123', 'password_confirmation' => 'newpassword123'];

    // Assert the shape deployments actually serve. With APP_DEBUG on — the
    // default under phpunit.xml — Laravel appends `exception`, `file`, `line`
    // and a full stack trace to every error body, and those differ between a
    // 404 raised by SubstituteBindings and one raised in failedAuthorization().
    // That difference is a debug-only artefact and is not what a caller sees,
    // but comparing bodies with it present would compare stack frames instead
    // of the thing under test.
    config(['app.debug' => false]);
});

it('answers the same id identically whether or not it exists', function () {
    // The exact definition of "indistinguishable": hold the URL constant and
    // vary only whether the row is there. Comparing two DIFFERENT ids would be
    // weaker — the 404 body echoes the id from the URL, which is the caller's
    // own input, so those always differ for uninteresting reasons.
    $this->actingAs($this->outsider);
    $id = $this->target->id;

    $whileItExists = $this->postJson("/api/users/{$id}/reset-password", $this->payload);

    $this->target->delete();

    $whileItDoesNot = $this->postJson("/api/users/{$id}/reset-password", $this->payload);

    expect($whileItExists->status())->toBe(404)
        ->and($whileItDoesNot->status())->toBe(404)
        ->and($whileItExists->json())->toEqual($whileItDoesNot->json())
        ->and($whileItExists->getContent())->toBe($whileItDoesNot->getContent());
});

it('does not distinguish a real id from a never-used one', function () {
    // The same property stated the other way round, tolerating only the echoed
    // id: both answers must be the generic not-found shape, not 403 vs 404.
    $this->actingAs($this->outsider);

    $real = $this->postJson("/api/users/{$this->target->id}/reset-password", $this->payload);
    $fake = $this->postJson('/api/users/99999/reset-password', $this->payload);

    $normalise = fn (string $body, int $id) => str_replace((string) $id, '{id}', $body);

    expect($real->status())->toBe($fake->status())
        ->and($normalise($real->getContent(), $this->target->id))
        ->toBe($normalise($fake->getContent(), 99999));
});

it('does not reset the password it refuses to acknowledge', function () {
    $original = $this->target->fresh()->password;

    $this->actingAs($this->outsider)
        ->postJson("/api/users/{$this->target->id}/reset-password", $this->payload)
        ->assertNotFound();

    expect($this->target->fresh()->password)->toBe($original)
        ->and($this->target->fresh()->must_change_password)->toBeFalse();
});

it('still lets an authorised caller reset a password', function () {
    // The carve-out must not cost the feature: a permitted caller is unaffected.
    $admin = User::where('username', 'super_admin')->first();

    $this->actingAs($admin)
        ->postJson("/api/users/{$this->target->id}/reset-password", $this->payload)
        ->assertOk()
        ->assertJson(['message' => 'Password reset successfully.']);

    expect($this->target->fresh()->must_change_password)->toBeTrue();
});

it('still tells an authorised caller when an id does not exist', function () {
    // Non-enumeration is owed to people who may not ask, not to people who may.
    $admin = User::where('username', 'super_admin')->first();

    $this->actingAs($admin)
        ->postJson('/api/users/99999/reset-password', $this->payload)
        ->assertNotFound();
});

it('does not leak existence through the unauthenticated path either', function () {
    // No token at all: 401 before anything is resolved, for both ids.
    $real = $this->postJson("/api/users/{$this->target->id}/reset-password", $this->payload);
    $fake = $this->postJson('/api/users/99999/reset-password', $this->payload);

    expect($real->status())->toBe(401)
        ->and($fake->status())->toBe(401)
        ->and($real->json())->toEqual($fake->json());
});
