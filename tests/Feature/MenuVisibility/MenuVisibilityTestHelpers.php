<?php

namespace Tests\Feature\MenuVisibility;

use App\Models\User;
use App\Services\MenuVisibility\MenuVisibility;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

/**
 * Menu Visibility (finish run round 6) in tests: staff with real Sanctum
 * tokens, since the administrator override is bound to the access token, and
 * a throwaway override password whose bcrypt hash stands in for the
 * deployment's MENU_VISIBILITY_OVERRIDE_PASSWORD_HASH.
 */
trait MenuVisibilityTestHelpers
{
    public const OVERRIDE_PASSWORD = 'Throwaway override 2026!';

    public function setUpOverrideHash(?string $password = self::OVERRIDE_PASSWORD): void
    {
        config(['menu_visibility.override_password_hash' => $password === null ? null : Hash::driver('bcrypt')->make($password, ['rounds' => 4])]);
    }

    public function staffWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function superAdmin(): User
    {
        return User::query()->where('username', 'super_admin')->firstOrFail();
    }

    public function tokenFor(User $user): string
    {
        return $user->createToken('auth-token', ['*'], now()->addMinutes(30))->plainTextToken;
    }

    /**
     * One request on a bearer token, with the guards forgotten first, so two
     * tokens in one test never share the user an earlier request resolved.
     */
    public function asToken(string $token, string $method, string $uri, array $data = [], array $server = []): TestResponse
    {
        $this->forgetSignIns();

        return $this->withServerVariables($server)->withToken($token)->json($method, $uri, $data);
    }

    /**
     * One request as its caller: a super admin's token on a staff route
     * (`staff`), the member token the test keeps in `$this->memberToken` on a
     * portal route (`member`), or nobody on a public one (`none`).
     *
     * @param  array{0: string, 1: string, 2: string, 3?: array<string, mixed>}  $request
     */
    public function callAs(array $request): TestResponse
    {
        [$method, $uri, $caller] = $request;

        $this->forgetSignIns();

        $token = match ($caller) {
            'staff' => $this->tokenFor($this->superAdmin()),
            'member' => $this->memberToken,
            default => null,
        };

        return $token === null
            ? $this->json($method, $uri, $request[3] ?? [])
            : $this->withToken($token)->json($method, $uri, $request[3] ?? []);
    }

    public function openOverride(string $token, array $server = []): TestResponse
    {
        return $this->asToken($token, 'POST', '/api/menu-visibility/override', ['password' => self::OVERRIDE_PASSWORD], $server);
    }

    /** @param  list<string>  $keys */
    public function hideModules(array $keys, ?User $by = null): void
    {
        app(MenuVisibility::class)->save($keys, $by ?? $this->superAdmin());
    }

    private function forgetSignIns(): void
    {
        Auth::forgetGuards();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withServerVariables([]);
    }
}
