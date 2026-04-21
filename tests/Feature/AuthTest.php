<?php

namespace Tests\Feature;

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
            'login' => 'admin',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_login_with_wrong_password(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'login' => 'admin',
            'password' => 'wrong',
        ]);

        $response->assertUnauthorized();
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
            'login' => 'admin',
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
