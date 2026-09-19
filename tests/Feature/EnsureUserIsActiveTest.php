<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

uses(TestCase::class);

/**
 * EnsureUserIsActive, plus the two AuthController actions that revoke the same
 * way — logout() and refresh().
 *
 * All three used to call `->delete()` straight on whatever
 * `currentAccessToken()` returned. That is only ever safe for API-token auth:
 * Sanctum hands a session-based caller a TransientToken, which declares exactly
 * `can()` and `cant()`, extends nothing and has no `__call` — so `->delete()`
 * on one is a fatal Error and the caller gets HTTP 500. A non-Sanctum guard
 * makes it worse still and returns null.
 *
 * The middleware's body had never been executed by this suite at all. Nothing
 * asserted on "Your account has been deactivated.", so a 500 sat on the
 * deactivation path — the exact path a locked-out operator walks — for its
 * whole life.
 *
 * Every example therefore drives ONE of two credentials deliberately, and the
 * file needs both to mean anything:
 *
 *   actingAs()   — resolves through the `web` guard, which Sanctum's Guard
 *                  checks BEFORE the bearer token (sanctum.guard = ['web'],
 *                  vendor/laravel/sanctum/src/Guard.php), so the caller carries
 *                  a TransientToken. This is what reproduced the 500, and it is
 *                  the shape almost every other test in this suite uses.
 *   withToken()  — a real PersonalAccessToken over HTTP. Only this proves the
 *                  intended 403/200 AND that the credential is genuinely
 *                  revoked, which a TransientToken can never show.
 *
 * Auth::forgetGuards() appears between two requests in the same example on
 * purpose: production builds a fresh container per request, but Laravel's HTTP
 * test harness reuses one application and RequestGuard memoises its user, so
 * without it a second call never re-resolves the token and would assert
 * nothing. Same reasoning as TokenIdleTimeoutTest.
 */
beforeEach(function () {
    $this->operator = User::factory()->create([
        'branch_id' => Branch::first()->id,
        'status' => 'active',
    ]);
});

/**
 * A real bearer token for $user, in the state Sanctum leaves a fresh one in.
 *
 * Long-lived on purpose: `auth.token_timeout` is 30 minutes and CheckTokenExpiry
 * runs ahead of EnsureUserIsActive in the stack, so a token that could expire
 * mid-example would answer 401 and hide whatever this file is actually asserting.
 *
 * @return array{0: string, 1: PersonalAccessToken}
 */
function operatorToken(User $user): array
{
    $new = $user->createToken('auth-token', ['*'], now()->addDays(30));

    return [$new->plainTextToken, $new->accessToken->fresh()];
}

// ── EnsureUserIsActive ──────────────────────────────────────────────────────

/**
 * The regression. Before the guard, this request died inside the middleware
 * with `Call to undefined method Laravel\Sanctum\TransientToken::delete()` and
 * returned 500 — so a deactivated session caller was told the server was
 * broken instead of being told their account was.
 */
it('refuses a deactivated session caller with 403 rather than erroring', function () {
    $this->operator->update(['status' => 'inactive']);

    $this->actingAs($this->operator)
        ->getJson('/api/auth/me')
        ->assertForbidden()
        ->assertJson(['message' => 'Your account has been deactivated.']);
});

it('refuses a deactivated bearer caller and revokes the token it arrived on', function () {
    [$plain, $token] = operatorToken($this->operator);

    $this->operator->update(['status' => 'inactive']);

    $this->withToken($plain)
        ->getJson('/api/auth/me')
        ->assertForbidden()
        ->assertJson(['message' => 'Your account has been deactivated.']);

    // Refusing once is not enough — the credential must not survive the refusal.
    expect(PersonalAccessToken::find($token->id))->toBeNull();
});

it('revokes only the credential the refused request arrived on', function () {
    // The middleware's job is "revoke the credential this request arrived on",
    // not "log the user out everywhere". The other token is not left usable by
    // this: the status check runs per request, so it is refused and revoked the
    // moment it is presented.
    [$plain, $used] = operatorToken($this->operator);
    [, $untouched] = operatorToken($this->operator);

    $this->operator->update(['status' => 'inactive']);

    $this->withToken($plain)->getJson('/api/auth/me')->assertForbidden();

    expect(PersonalAccessToken::find($used->id))->toBeNull()
        ->and(PersonalAccessToken::find($untouched->id))->not->toBeNull();
});

it('lets an active bearer caller straight through', function () {
    [$plain, $token] = operatorToken($this->operator);

    $this->withToken($plain)->getJson('/api/auth/me')->assertOk();

    expect(PersonalAccessToken::find($token->id))->not->toBeNull();
});

/**
 * The null case, which `?->` on line 13 hinted at and line 14 then ignored.
 *
 * `currentAccessToken()` is null whenever the caller was not resolved through
 * Sanctum's guard — the middleware also runs on the public-registration routes
 * behind OptionalSanctumAuth and AllowAuthOrSubmissionToken. Driven directly
 * rather than over HTTP because no route in this application currently
 * authenticates a user on a non-Sanctum guard, and a test that cannot be
 * written today is exactly the one that stops being true tomorrow.
 */
it('refuses a deactivated caller whose guard supplies no access token', function () {
    $this->operator->update(['status' => 'inactive']);

    $request = Request::create('/api/auth/me', 'GET');
    $request->setUserResolver(fn () => $this->operator->fresh());

    expect($this->operator->fresh()->currentAccessToken())->toBeNull();

    $response = (new EnsureUserIsActive)->handle($request, fn () => response('next'));

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getContent())->toContain('Your account has been deactivated.');
});

it('gets out of the way when no user is attached at all', function () {
    // The public-registration stack runs this middleware for anonymous callers.
    $request = Request::create('/api/borrowers', 'POST');

    $response = (new EnsureUserIsActive)->handle($request, fn () => response('next'));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('next');
});

it('lets an active session caller through', function () {
    $this->actingAs($this->operator)->getJson('/api/auth/me')->assertOk();
});

// ── AuthController::logout ──────────────────────────────────────────────────

/**
 * Second site of the same fatal call. A session caller hitting logout got a 500
 * and stayed logged in.
 */
it('logs a session caller out instead of erroring', function () {
    $this->actingAs($this->operator)
        ->postJson('/api/auth/logout')
        ->assertOk()
        ->assertJson(['message' => 'Logged out successfully.']);

    // And it is a real logout, not a 200 that did nothing: for a session caller
    // the session IS the credential, so ending it is the whole point.
    expect(Auth::guard('web')->check())->toBeFalse();
});

it('revokes only the bearer token the logout arrived on', function () {
    [$plain, $used] = operatorToken($this->operator);
    [, $untouched] = operatorToken($this->operator);

    $this->withToken($plain)
        ->postJson('/api/auth/logout')
        ->assertOk()
        ->assertJson(['message' => 'Logged out successfully.']);

    expect(PersonalAccessToken::find($used->id))->toBeNull()
        ->and(PersonalAccessToken::find($untouched->id))->not->toBeNull();
});

it('leaves a logged-out bearer token unusable', function () {
    [$plain] = operatorToken($this->operator);

    $this->withToken($plain)->postJson('/api/auth/logout')->assertOk();

    Auth::forgetGuards();

    $this->withToken($plain)->getJson('/api/auth/me')->assertUnauthorized();
});

// ── AuthController::refresh ─────────────────────────────────────────────────

/**
 * Third site. Refusing is deliberate, and it is NOT the same answer as logout's.
 *
 * refresh() means "revoke the token this request arrived on and issue its
 * replacement". A session caller has no token to revoke, so minting one would
 * not be a refresh at all — it would silently convert a cookie session into a
 * standalone bearer credential that outlives the session that produced it,
 * including outliving logout. There is nothing to rotate, so the request is
 * refused rather than quietly upgraded.
 */
it('refuses to rotate for a session caller that has no token', function () {
    $this->actingAs($this->operator)
        ->postJson('/api/auth/refresh')
        ->assertStatus(400)
        ->assertJson(['code' => 'no_token_to_refresh']);

    expect($this->operator->tokens()->count())->toBe(0);
});

it('rotates a bearer token and revokes the old one', function () {
    [$plain, $old] = operatorToken($this->operator);

    $fresh = $this->withToken($plain)
        ->postJson('/api/auth/refresh')
        ->assertOk()
        ->json('token');

    expect($fresh)->not->toBeNull()
        ->and($fresh)->not->toBe($plain)
        ->and(PersonalAccessToken::find($old->id))->toBeNull();

    Auth::forgetGuards();

    $this->withToken($fresh)->getJson('/api/auth/me')->assertOk();

    Auth::forgetGuards();

    $this->withToken($plain)->getJson('/api/auth/me')->assertUnauthorized();
});
