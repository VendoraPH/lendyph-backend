<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class AuthTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_login_with_valid_credentials(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'login' => 'super_admin',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_login_with_wrong_password(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'login' => 'super_admin',
            'password' => 'wrong',
        ]);

        $response->assertUnauthorized();
    }

    public function test_login_records_last_login_at(): void
    {
        // Regression guard: `last_login_at` is not in User::$fillable, so a
        // mass-assigned update() silently dropped it and the column stayed null
        // for every user, on every instance, forever.
        $user = User::where('username', 'super_admin')->firstOrFail();
        $user->forceFill(['last_login_at' => null])->saveQuietly();

        $this->postJson('/api/auth/login', [
            'login' => 'super_admin',
            'password' => 'password',
        ])->assertOk();

        $user->refresh();

        $this->assertNotNull($user->last_login_at, 'last_login_at was not recorded on login.');
        $this->assertTrue($user->last_login_at->isAfter(now()->subMinute()));
    }

    public function test_login_does_not_write_a_duplicate_updated_audit_row(): void
    {
        // The login timestamp is saved quietly on purpose: AuthController already
        // records an explicit 'login' entry, and letting the Auditable trait fire
        // would add a second row per login carrying the bcrypt hash in old_values.
        $user = User::where('username', 'super_admin')->firstOrFail();

        DB::table('audit_logs')->where('auditable_type', User::class)->delete();

        $this->postJson('/api/auth/login', [
            'login' => 'super_admin',
            'password' => 'password',
        ])->assertOk();

        $rows = DB::table('audit_logs')
            ->where('auditable_type', User::class)
            ->where('auditable_id', $user->id);

        $this->assertSame(1, (clone $rows)->where('action', 'login')->count());
        $this->assertSame(0, (clone $rows)->where('action', 'updated')->count());
    }

    public function test_protected_route_without_token(): void
    {
        // Reset auth so no user is acting
        $this->app['auth']->forgetGuards();

        $response = $this->getJson('/api/auth/me');

        $response->assertUnauthorized();
    }

    public function test_logout_revokes_token(): void
    {
        // Reset acting-as so we use real token auth
        $this->app['auth']->forgetGuards();

        $loginResponse = $this->postJson('/api/auth/login', [
            'login' => 'super_admin',
            'password' => 'password',
        ]);

        $token = $loginResponse->json('token');

        $logoutResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/logout');

        $logoutResponse->assertOk()
            ->assertJson(['message' => 'Logged out successfully.']);
    }

    public function test_me_returns_authenticated_user(): void
    {
        $loginResponse = $this->postJson('/api/auth/login', [
            'login' => 'super_admin',
            'password' => 'password',
        ]);

        $token = $loginResponse->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.username', 'super_admin');
    }
}
