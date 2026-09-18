<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

uses(TestCase::class);

/**
 * CheckTokenExpiry — the 30-minute idle timeout.
 *
 * Every example here drives a REAL bearer token over HTTP. That is the whole
 * point of the file: the middleware was dead code for its entire life, and it
 * stayed that way because nothing ever exercised it. `actingAs()` cannot catch
 * these bugs — it attaches the user before any middleware runs and yields a
 * TransientToken, so the idle path is never entered.
 *
 * Tokens here are minted with a far-future `expires_at`, the shape
 * AuthController::login gives a "remember me" sign-in. Sanctum's own absolute
 * expiry works correctly and returns 401 on its own; for a normal 30-minute
 * sign-in it always lands first, which would mask whether the idle check ran at
 * all. The remembered token is the one with a genuine idle gap to close.
 */
beforeEach(function () {
    $this->operator = User::where('username', 'super_admin')->first();
});

/**
 * Mint a long-lived token and force its stored activity timestamps.
 *
 * @return array{0: string, 1: PersonalAccessToken}
 */
function rememberedToken(User $user, ?int $idleMinutes, int $ageMinutes = 120): array
{
    $new = $user->createToken('auth-token', ['*'], now()->addDays(30));

    $new->accessToken->forceFill([
        'created_at' => now()->subMinutes($ageMinutes),
        'last_used_at' => $idleMinutes === null ? null : now()->subMinutes($idleMinutes),
    ])->save();

    return [$new->plainTextToken, $new->accessToken->fresh()];
}

it('rejects a token that has been idle past the timeout', function () {
    [$plain, $token] = rememberedToken($this->operator, idleMinutes: 31);

    $response = $this->withToken($plain)->getJson('/api/auth/me');

    $response->assertUnauthorized()
        ->assertJson(['message' => 'Session expired due to inactivity.']);

    // The middleware revokes as well as refuses — the token must be gone, not
    // merely rejected this once.
    expect(PersonalAccessToken::find($token->id))->toBeNull();
});

it('accepts a token used within the timeout', function () {
    [$plain] = rememberedToken($this->operator, idleMinutes: 5);

    $this->withToken($plain)->getJson('/api/auth/me')->assertOk();
});

it('treats a never-used token as idle since it was created', function () {
    // Sanctum leaves `last_used_at` null until the first authenticated request,
    // so a token minted and then abandoned has no activity to measure. It is
    // idle from the moment it was issued.
    [$plain, $token] = rememberedToken($this->operator, idleMinutes: null, ageMinutes: 45);

    $this->withToken($plain)->getJson('/api/auth/me')
        ->assertUnauthorized()
        ->assertJson(['message' => 'Session expired due to inactivity.']);

    expect(PersonalAccessToken::find($token->id))->toBeNull();
});

it('keeps a session alive across requests inside the window', function () {
    // Each accepted request refreshes `last_used_at`, so an operator working
    // steadily is never logged out. Guards against a fix that measures from a
    // fixed point and expires everyone on a schedule regardless of activity.
    //
    // forgetGuards() between the two calls is not a workaround for the code
    // under test, it is what makes this a two-request test at all. Production
    // builds a fresh container per request, so Sanctum re-resolves the token
    // and re-fires TokenAuthenticated every time. Laravel's HTTP test harness
    // reuses one application, and RequestGuard memoises its user, so without
    // this the second call would never reach Sanctum and would prove nothing.
    [$plain] = rememberedToken($this->operator, idleMinutes: 20);

    $this->withToken($plain)->getJson('/api/auth/me')->assertOk();

    $this->travel(20)->minutes();
    Auth::forgetGuards();

    $this->withToken($plain)->getJson('/api/auth/me')->assertOk();

    $this->travelBack();
});

/**
 * Regression guard for cause #1.
 *
 * `last_used_at` is an Eloquent attribute resolved through __get, not a
 * declared PHP property, so `property_exists($token, 'last_used_at')` is FALSE
 * and the guard clause that used to open this middleware short-circuited on
 * every single request.
 */
it('does not gate the idle check on property_exists', function () {
    $token = new PersonalAccessToken;
    $token->forceFill(['last_used_at' => now()]);

    // The exact pair of facts that made the old guard clause unreachable: the
    // attribute reads back fine, and property_exists() still says it is absent.
    expect(property_exists($token, 'last_used_at'))->toBeFalse()
        ->and($token->last_used_at)->not->toBeNull();

    // And the behaviour that proves the clause is gone: an idle token is now
    // refused over HTTP, which is impossible while a false condition gates it.
    [$plain] = rememberedToken($this->operator, idleMinutes: 31);

    $this->withToken($plain)->getJson('/api/auth/me')
        ->assertUnauthorized()
        ->assertJson(['message' => 'Session expired due to inactivity.']);
});

/**
 * Regression guard for cause #2.
 *
 * Sanctum's Guard runs `forceFill(['last_used_at' => now()])->save()` on every
 * authenticated request, BEFORE any middleware sees the token. Reading the
 * column inside the middleware therefore always measures ~0 minutes elapsed.
 * This proves the overwrite really happens, so the fix cannot regress to
 * reading the column directly.
 */
it('confirms sanctum overwrites last_used_at before middleware runs', function () {
    [$plain, $token] = rememberedToken($this->operator, idleMinutes: 5);

    expect($token->last_used_at->diffInMinutes(now()))->toBeGreaterThan(4);

    $this->withToken($plain)->getJson('/api/auth/me')->assertOk();

    // Same row, re-read after the request: Sanctum has moved it to now().
    expect(PersonalAccessToken::find($token->id)->last_used_at->diffInMinutes(now()))
        ->toBeLessThan(1);
});

it('leaves session-based callers alone', function () {
    // actingAs() yields a TransientToken, which has no idle window at all.
    $this->actingAs($this->operator)->getJson('/api/auth/me')->assertOk();
});
