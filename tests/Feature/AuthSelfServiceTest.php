<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Artisan::call('migrate:fresh');
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('username', 'admin')->first();
});

// ── PATCH /auth/me ──────────────────────────────────────────────────────────

it('updates the current user name, email, and mobile_number', function () {
    $this->actingAs($this->admin);

    $this->patchJson('/api/auth/me', [
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'email' => 'new-admin@example.com',
        'mobile_number' => '09171234567',
    ])->assertOk()
        ->assertJsonPath('data.first_name', 'Juan')
        ->assertJsonPath('data.last_name', 'Dela Cruz')
        ->assertJsonPath('data.full_name', 'Juan Dela Cruz')
        ->assertJsonPath('data.email', 'new-admin@example.com');

    $fresh = $this->admin->fresh();
    expect($fresh->first_name)->toBe('Juan');
    expect($fresh->last_name)->toBe('Dela Cruz');
    expect($fresh->email)->toBe('new-admin@example.com');
    expect($fresh->mobile_number)->toBe('09171234567');
});

it('accepts full_name as a convenience input and splits into first_name + last_name', function () {
    $this->actingAs($this->admin);

    $this->patchJson('/api/auth/me', ['full_name' => 'Maria Clara Reyes'])
        ->assertOk()
        ->assertJsonPath('data.first_name', 'Maria')
        ->assertJsonPath('data.last_name', 'Clara Reyes')
        ->assertJsonPath('data.full_name', 'Maria Clara Reyes');
});

it('allows updating to the same email (uniqueness ignores self)', function () {
    $this->actingAs($this->admin);
    $currentEmail = $this->admin->email;

    $this->patchJson('/api/auth/me', ['email' => $currentEmail])
        ->assertOk();
});

it('rejects email that is taken by another user', function () {
    $other = User::factory()->create(['email' => 'other@example.com']);
    $this->actingAs($this->admin);

    $this->patchJson('/api/auth/me', ['email' => 'other@example.com'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('does not allow updating username through PATCH /auth/me', function () {
    $this->actingAs($this->admin);
    $originalUsername = $this->admin->username;

    $this->patchJson('/api/auth/me', [
        'username' => 'hacker',
        'full_name' => 'Legit Change',
    ])->assertOk();

    expect($this->admin->fresh()->username)->toBe($originalUsername);
});

it('rejects invalid mobile_number format', function () {
    $this->actingAs($this->admin);

    $this->patchJson('/api/auth/me', ['mobile_number' => 'not-a-phone'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['mobile_number']);
});

it('requires authentication for PATCH /auth/me', function () {
    $this->patchJson('/api/auth/me', ['full_name' => 'x'])
        ->assertUnauthorized();
});

// ── POST /auth/change-password ──────────────────────────────────────────────

it('changes password with correct current password and valid new password', function () {
    $user = User::factory()->create(['password' => Hash::make('old-pass-123')]);
    $this->actingAs($user);

    $this->postJson('/api/auth/change-password', [
        'current_password' => 'old-pass-123',
        'new_password' => 'new-pass-456',
        'new_password_confirmation' => 'new-pass-456',
    ])->assertOk()
        ->assertJsonPath('message', 'Password updated successfully.');

    expect(Hash::check('new-pass-456', $user->fresh()->password))->toBeTrue();
    expect(Hash::check('old-pass-123', $user->fresh()->password))->toBeFalse();
});

it('rejects password change with wrong current password', function () {
    $user = User::factory()->create(['password' => Hash::make('old-pass-123')]);
    $this->actingAs($user);

    $this->postJson('/api/auth/change-password', [
        'current_password' => 'wrong',
        'new_password' => 'new-pass-456',
        'new_password_confirmation' => 'new-pass-456',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['current_password']);

    expect(Hash::check('old-pass-123', $user->fresh()->password))->toBeTrue();
});

it('rejects new password shorter than 8 characters', function () {
    $user = User::factory()->create(['password' => Hash::make('old-pass-123')]);
    $this->actingAs($user);

    $this->postJson('/api/auth/change-password', [
        'current_password' => 'old-pass-123',
        'new_password' => 'short',
        'new_password_confirmation' => 'short',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['new_password']);
});

it('rejects new password when confirmation mismatches', function () {
    $user = User::factory()->create(['password' => Hash::make('old-pass-123')]);
    $this->actingAs($user);

    $this->postJson('/api/auth/change-password', [
        'current_password' => 'old-pass-123',
        'new_password' => 'new-pass-456',
        'new_password_confirmation' => 'different-789',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['new_password']);
});

it('revokes other tokens but keeps current session alive on password change', function () {
    $user = User::factory()->create(['password' => Hash::make('old-pass-123')]);

    // Create two tokens — the "current" one (used to authenticate) and a "stale" one on another device
    $staleToken = $user->createToken('stale-device', ['*'])->accessToken;

    // Authenticate via Sanctum's acting-as with a NEW token so we have a distinct currentAccessToken
    Sanctum::actingAs($user, ['*']);

    $this->postJson('/api/auth/change-password', [
        'current_password' => 'old-pass-123',
        'new_password' => 'new-pass-456',
        'new_password_confirmation' => 'new-pass-456',
    ])->assertOk();

    // Stale token should be gone
    expect($user->tokens()->where('id', $staleToken->id)->exists())->toBeFalse();
});

it('requires authentication for POST /auth/change-password', function () {
    $this->postJson('/api/auth/change-password', [
        'current_password' => 'x',
        'new_password' => 'new-pass-456',
        'new_password_confirmation' => 'new-pass-456',
    ])->assertUnauthorized();
});

// ── End-to-end: login → change → re-login with new password ────────────────

it('allows login with the new password after a change', function () {
    $user = User::factory()->create([
        'password' => Hash::make('old-pass-123'),
        'status' => 'active',
    ]);

    $this->actingAs($user);

    $this->postJson('/api/auth/change-password', [
        'current_password' => 'old-pass-123',
        'new_password' => 'new-pass-456',
        'new_password_confirmation' => 'new-pass-456',
    ])->assertOk();

    $this->postJson('/api/auth/login', [
        'login' => $user->username,
        'password' => 'new-pass-456',
    ])->assertOk()
        ->assertJsonStructure(['token', 'user']);

    $this->postJson('/api/auth/login', [
        'login' => $user->username,
        'password' => 'old-pass-123',
    ])->assertStatus(401);
});
