<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Forced password change after an administrator reset.
 *
 * The contract under test, end to end:
 *   POST /users/{user}/reset-password  → sets   users.must_change_password
 *   POST /auth/change-password         → clears users.must_change_password
 *   anything else, while it is set     → 423 { code: 'password_change_required' }
 */
class ForcePasswordChangeTest extends TestCase
{
    use SetupLendyPH;

    private const TEMP_PASSWORD = 'temp-pass-123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function makeOperator(string $password = 'old-pass-123'): User
    {
        return User::factory()->create([
            'password' => Hash::make($password),
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);
    }

    /**
     * Authenticate the NEXT requests with a real bearer token instead of the
     * session user `seedAndLogin()` installed.
     *
     * Both halves of this matter:
     *
     * - `Laravel\Sanctum\Guard::__invoke()` consults `config('sanctum.guard')`
     *   — `['web']` here — BEFORE it looks at the bearer token. Left alone,
     *   every call below would quietly answer as super_admin and each
     *   assertion would pass for the wrong reason.
     * - `Illuminate\Auth\RequestGuard::user()` memoises whoever it resolved,
     *   and one AuthManager serves every request in a test, so without the
     *   reset the second identity in a test is simply ignored.
     */
    private function bearer(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function loginAndGetToken(User $user, string $password): string
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => ''])
            ->postJson('/api/auth/login', [
                'login' => $user->username,
                'password' => $password,
            ])
            ->assertOk()
            ->json('token');
    }

    /** Reset $user's password as super_admin, then log in on the new one. */
    private function resetThenLogin(User $user): string
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin)
            ->postJson("/api/users/{$user->id}/reset-password", [
                'password' => self::TEMP_PASSWORD,
                'password_confirmation' => self::TEMP_PASSWORD,
            ])
            ->assertOk();

        return $this->loginAndGetToken($user, self::TEMP_PASSWORD);
    }

    private function assertLocked(TestResponse $response): void
    {
        $response->assertStatus(423)
            ->assertJsonPath('code', 'password_change_required')
            ->assertJsonPath('must_change_password', true);
    }

    // ── Setting the flag ────────────────────────────────────────────────────

    public function test_an_admin_password_reset_sets_must_change_password(): void
    {
        $operator = $this->makeOperator();
        $this->assertFalse($operator->must_change_password);

        $this->postJson("/api/users/{$operator->id}/reset-password", [
            'password' => self::TEMP_PASSWORD,
            'password_confirmation' => self::TEMP_PASSWORD,
        ])->assertOk();

        $operator->refresh();

        // Guards the silent-no-op failure mode: the column is outside
        // User::$fillable, so a mass-assigned update() would have dropped it
        // here and the reset would have changed nothing but the password.
        $this->assertTrue($operator->must_change_password);
        $this->assertTrue(Hash::check(self::TEMP_PASSWORD, $operator->password));
    }

    public function test_an_admin_password_reset_revokes_the_targets_existing_tokens(): void
    {
        $operator = $this->makeOperator();
        $stale = $operator->createToken('stale-device', ['*'])->accessToken;

        $this->postJson("/api/users/{$operator->id}/reset-password", [
            'password' => self::TEMP_PASSWORD,
            'password_confirmation' => self::TEMP_PASSWORD,
        ])->assertOk();

        // Both halves of the reset landed. The flag is written in the same
        // UPDATE as the password and the revoke shares its transaction, so
        // there is no ordering in which a live token outlives an unflagged
        // account — and a token that somehow survived would be met with a 423.
        $this->assertFalse($operator->tokens()->where('id', $stale->id)->exists());
        $this->assertTrue($operator->fresh()->must_change_password);
    }

    public function test_a_normal_login_does_not_set_must_change_password(): void
    {
        $operator = $this->makeOperator();

        $token = $this->loginAndGetToken($operator, 'old-pass-123');

        $this->assertFalse($operator->fresh()->must_change_password);
        $this->bearer($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.must_change_password', false);
    }

    public function test_no_existing_user_is_flagged_by_the_migration(): void
    {
        // The default has to be false, or deploying this locks every user of
        // every instance out of the product at once — administrators included.
        $this->makeOperator();

        $this->assertSame(0, User::where('must_change_password', true)->count());
        $this->assertFalse($this->admin->fresh()->must_change_password);
    }

    // ── Exposing the flag ───────────────────────────────────────────────────

    public function test_the_login_response_exposes_must_change_password(): void
    {
        $operator = $this->makeOperator();
        $this->resetThenLogin($operator);

        $this->app['auth']->forgetGuards();

        // login() nests UserResource under `user` (unwrapped); /auth/me returns
        // it at the top level (wrapped in `data`). One resource, both shapes.
        $this->withHeaders(['Authorization' => ''])
            ->postJson('/api/auth/login', [
                'login' => $operator->username,
                'password' => self::TEMP_PASSWORD,
            ])
            ->assertOk()
            ->assertJsonPath('user.must_change_password', true);
    }

    public function test_auth_me_exposes_must_change_password(): void
    {
        $operator = $this->makeOperator();
        $token = $this->resetThenLogin($operator);

        $this->bearer($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true);
    }

    // ── Enforcement ─────────────────────────────────────────────────────────

    public function test_a_locked_user_is_refused_on_an_ordinary_endpoint(): void
    {
        $operator = $this->makeOperator();
        $operator->assignRole('loan_officer');
        $token = $this->resetThenLogin($operator);

        $response = $this->bearer($token)->getJson('/api/borrowers');

        $this->assertLocked($response);

        // 423 and not 403: this API answers 403 for "you lack the permission",
        // and the client has to route the two to different screens.
        $response->assertJson([
            'message' => 'Your password was reset by an administrator. You must set a new password before continuing.',
            'code' => 'password_change_required',
            'must_change_password' => true,
        ]);
    }

    public function test_a_locked_user_is_refused_on_patch_auth_me(): void
    {
        // Same URI as the allowlisted GET /auth/me, different handler. An
        // allowlist keyed on the path alone would have let a profile edit
        // through — and updateMe() is an ordinary write, not an escape hatch.
        $operator = $this->makeOperator();
        $originalFirstName = $operator->first_name;
        $token = $this->resetThenLogin($operator);

        $this->assertLocked(
            $this->bearer($token)->patchJson('/api/auth/me', ['full_name' => 'Sneaky Edit'])
        );

        $this->assertSame($originalFirstName, $operator->fresh()->first_name);
    }

    public function test_a_locked_user_is_refused_on_auth_refresh(): void
    {
        // Deliberately outside the allowlist: this state is meant to be short,
        // and handing out a fresh 30-minute token is a privilege, not an
        // escape hatch. A locked user who idles out logs in again.
        $operator = $this->makeOperator();
        $token = $this->resetThenLogin($operator);

        $this->assertLocked($this->bearer($token)->postJson('/api/auth/refresh'));
    }

    public function test_a_locked_user_is_refused_on_the_authenticated_borrower_create_route(): void
    {
        // POST /borrowers lives OUTSIDE the auth group so anonymous applicants
        // can register, but it still serves operators on a bearer token. If the
        // middleware were only applied to the auth group, a locked operator
        // could still write borrower PII through this door.
        $operator = $this->makeOperator();
        $operator->assignRole('loan_officer');
        $token = $this->resetThenLogin($operator);

        $this->assertLocked($this->bearer($token)->postJson('/api/borrowers', [
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'branch_id' => $this->branch->id,
        ]));
    }

    public function test_anonymous_registration_is_not_affected_by_the_middleware(): void
    {
        // No user attached → the middleware must stand aside entirely. The
        // payload here is intentionally incomplete; anything but a 423 proves
        // the request reached validation instead of the lock.
        $this->app['auth']->forgetGuards();

        $this->withHeaders(['Authorization' => ''])
            ->postJson('/api/borrowers', [])
            ->assertStatus(422);
    }

    // ── The escape hatch ────────────────────────────────────────────────────

    public function test_a_locked_user_can_still_reach_auth_me(): void
    {
        $operator = $this->makeOperator();
        $token = $this->resetThenLogin($operator);

        $this->bearer($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $operator->id)
            ->assertJsonPath('data.must_change_password', true);
    }

    public function test_a_locked_user_can_still_change_their_password(): void
    {
        $operator = $this->makeOperator();
        $token = $this->resetThenLogin($operator);

        $this->bearer($token)->postJson('/api/auth/change-password', [
            'current_password' => self::TEMP_PASSWORD,
            'new_password' => 'chosen-by-me-456',
            'new_password_confirmation' => 'chosen-by-me-456',
        ])->assertOk()
            ->assertJsonPath('message', 'Password updated successfully.');

        $this->assertTrue(Hash::check('chosen-by-me-456', $operator->fresh()->password));
    }

    public function test_a_locked_user_can_still_log_out(): void
    {
        $operator = $this->makeOperator();
        $token = $this->resetThenLogin($operator);

        $this->bearer($token)->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out successfully.');

        $this->assertSame(0, $operator->fresh()->tokens()->count());
    }

    // ── Clearing the flag ───────────────────────────────────────────────────

    public function test_changing_the_password_clears_the_flag_and_restores_normal_access(): void
    {
        $operator = $this->makeOperator();
        $operator->assignRole('loan_officer');
        $token = $this->resetThenLogin($operator);

        $this->assertLocked($this->bearer($token)->getJson('/api/borrowers'));

        $this->bearer($token)->postJson('/api/auth/change-password', [
            'current_password' => self::TEMP_PASSWORD,
            'new_password' => 'chosen-by-me-456',
            'new_password_confirmation' => 'chosen-by-me-456',
        ])->assertOk();

        $this->assertFalse($operator->fresh()->must_change_password);

        // change-password keeps the CURRENT token alive (it only revokes the
        // others), so the very same bearer walks straight back into the app.
        $this->bearer($token)->getJson('/api/borrowers')->assertOk();
        $this->bearer($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.must_change_password', false);
    }

    public function test_a_user_who_never_had_a_reset_is_never_locked_out(): void
    {
        $operator = $this->makeOperator();
        $operator->assignRole('loan_officer');

        $token = $this->loginAndGetToken($operator, 'old-pass-123');

        $this->bearer($token)->getJson('/api/borrowers')->assertOk();
        $this->bearer($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.must_change_password', false);
    }

    public function test_a_normal_password_change_leaves_an_unflagged_user_unflagged(): void
    {
        $operator = $this->makeOperator();
        $token = $this->loginAndGetToken($operator, 'old-pass-123');

        $this->bearer($token)->postJson('/api/auth/change-password', [
            'current_password' => 'old-pass-123',
            'new_password' => 'chosen-by-me-456',
            'new_password_confirmation' => 'chosen-by-me-456',
        ])->assertOk();

        $this->assertFalse($operator->fresh()->must_change_password);
    }

    // ── The flag is not user-writable ───────────────────────────────────────

    public function test_the_flag_cannot_be_set_through_the_admin_user_update_payload(): void
    {
        $operator = $this->makeOperator();

        $this->putJson("/api/users/{$operator->id}", [
            'first_name' => 'Renamed',
            'must_change_password' => true,
        ])->assertOk();

        $this->assertSame('Renamed', $operator->fresh()->first_name);
        $this->assertFalse($operator->fresh()->must_change_password);
    }

    public function test_the_flag_cannot_be_cleared_through_the_admin_user_update_payload(): void
    {
        // The interesting direction. A locked account must not be unlockable by
        // an ordinary edit — least of all by an admin who already knows the
        // temporary password and would then own a permanent credential.
        $operator = $this->makeOperator();
        $this->resetThenLogin($operator);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin)
            ->putJson("/api/users/{$operator->id}", [
                'first_name' => 'Renamed',
                'must_change_password' => false,
            ])->assertOk();

        $this->assertTrue($operator->fresh()->must_change_password);
    }

    public function test_the_flag_cannot_be_set_when_creating_a_user(): void
    {
        $this->postJson('/api/users', [
            'first_name' => 'New',
            'last_name' => 'Operator',
            'username' => 'new_operator',
            'email' => 'new_operator@example.com',
            'password' => 'brand-new-123',
            'password_confirmation' => 'brand-new-123',
            'branch_id' => $this->branch->id,
            'role' => 'loan_officer',
            'must_change_password' => true,
        ])->assertCreated();

        $this->assertFalse(User::where('username', 'new_operator')->firstOrFail()->must_change_password);
    }

    public function test_the_flag_cannot_be_cleared_through_patch_auth_me(): void
    {
        // An unflagged user proves the payload key is inert on this endpoint;
        // a flagged one never reaches it at all (see the PATCH /auth/me test).
        $operator = $this->makeOperator();
        $token = $this->loginAndGetToken($operator, 'old-pass-123');

        $this->bearer($token)->patchJson('/api/auth/me', [
            'full_name' => 'Legit Change',
            'must_change_password' => true,
        ])->assertOk();

        $this->assertFalse($operator->fresh()->must_change_password);
    }
}
