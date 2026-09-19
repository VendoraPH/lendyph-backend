<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * How fast a caller who cannot use user management may find that out.
 *
 * The five `{user}` routes answer a permission-less caller with the same 404 a
 * missing id produces, so one probe learns nothing — but the SWEEP was free.
 * These routes named no limiter of their own, so an id walk ran at the flat
 * `api` ceiling of 60/min: 3,600 sequential ids an hour, from any valid token,
 * silently. A coop's staff list is a few dozen accounts.
 *
 * The shape is the one CsvImportUploadRateLimitTest pins for imports, and so
 * is the idiom: resolve the limiter closure off the facade with a synthetic
 * Request, then assert on the Limit it returns. The one difference is the
 * direction — the import branch RAISES a ceiling for people who can import,
 * this one LOWERS it for people who cannot manage users, and must leave
 * everyone else exactly where they were.
 */
class UserRouteRateLimitTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function withRole(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole(Role::where('name', $role)->firstOrFail());

        return $user;
    }

    /**
     * A caller holding exactly one user-management permission and nothing else,
     * which is a role the UI can build and the seeder does not ship.
     */
    private function withPermission(string $permission): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);

        $role = Role::create(['name' => 'spec:'.$permission, 'guard_name' => 'web']);
        $role->syncPermissions([$permission]);

        $user->assignRole($role);

        return $user;
    }

    private function requestFor(string $routeName, ?User $user = null): Request
    {
        $request = Request::create('/api/users/1', 'GET');

        $route = new RoutingRoute(['GET'], 'api/users/{user}', []);
        $route->name($routeName);

        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    /**
     * @return list<string>
     */
    private function userRouteNames(): array
    {
        return ['users.index', 'users.store', 'users.show', 'users.update',
            'users.deactivate', 'users.reactivate', 'users.reset-password'];
    }

    /**
     * The load-bearing one, and the reason the routes file changed at all.
     *
     * `apiResource` names its four for free; deactivate, reactivate and
     * reset-password were anonymous, so `routeIs('users.*')` would have covered
     * four of the seven — and missed the exact three that change an account. A
     * missing name here is silent: the predicate just does not match and the
     * caller keeps the full 60.
     */
    public function test_every_user_route_is_actually_named_in_the_users_family(): void
    {
        foreach ($this->userRouteNames() as $name) {
            $this->assertNotNull(
                Route::getRoutes()->getByName($name),
                "Route [{$name}] is not registered, so the `users.*` limiter branch cannot see it."
            );
        }

        // The inverse: nothing OUTSIDE user management may answer to the
        // predicate, or it would inherit the denied ceiling.
        $stray = collect(Route::getRoutes()->getRoutesByName())
            ->keys()
            ->filter(fn (string $name) => str_starts_with($name, 'users.'))
            ->reject(fn (string $name) => in_array($name, $this->userRouteNames(), true))
            ->all();

        $this->assertSame([], array_values($stray));
    }

    public function test_a_caller_without_any_user_permission_is_cut_to_the_denied_ceiling(): void
    {
        $collector = $this->withRole('collector');
        $limiter = RateLimiter::limiter('api');

        foreach ($this->userRouteNames() as $name) {
            $limit = $limiter($this->requestFor($name, $collector));

            $this->assertSame(10, $limit->maxAttempts, "{$name} still hands a permission-less caller the full budget.");
            $this->assertStringStartsWith('users:api:denied:', $limit->key);
        }
    }

    /**
     * The denied tier is its own counter. Being cut off while probing users
     * must not spend the budget the same person needs for the collections
     * screens they are actually employed to use — and a sweep must not be able
     * to hide inside ordinary traffic on a shared one.
     */
    public function test_the_denied_counter_is_separate_from_the_shared_api_budget(): void
    {
        $collector = $this->withRole('collector');
        $limiter = RateLimiter::limiter('api');

        $probe = $limiter($this->requestFor('users.show', $collector));
        $ordinary = $limiter($this->requestFor('borrowers.index', $collector));

        $this->assertSame(10, $probe->maxAttempts);
        $this->assertSame(60, $ordinary->maxAttempts);
        $this->assertNotSame($probe->key, $ordinary->key);
    }

    /**
     * And it is per caller, not per address. TRUSTED_PROXIES ships empty and
     * every browser call arrives through the frontend's server-side rewrite, so
     * `$request->ip()` is one value for a whole deployment — keying on it would
     * let one prober throttle every member of staff out of user management.
     */
    public function test_the_denied_counter_is_per_caller(): void
    {
        $one = $this->withRole('collector');
        $two = $this->withRole('viewer');

        $limiter = RateLimiter::limiter('api');

        $this->assertNotSame(
            $limiter($this->requestFor('users.show', $one))->key,
            $limiter($this->requestFor('users.show', $two))->key,
        );

        $this->assertStringEndsWith((string) $one->id, $limiter($this->requestFor('users.show', $one))->key);
    }

    /**
     * Nobody's budget grows. A permitted caller falls through to the shared
     * 60/min on the plain key rather than getting a `users:` bucket of their
     * own — a second 60/min counter would quietly hand every admin 120 a minute
     * across the two.
     */
    public function test_a_permitted_caller_stays_on_the_shared_sixty(): void
    {
        $admin = $this->withRole('admin');
        $limiter = RateLimiter::limiter('api');

        $usersLimit = $limiter($this->requestFor('users.show', $admin));
        $ordinaryLimit = $limiter($this->requestFor('borrowers.index', $admin));

        $this->assertSame(60, $usersLimit->maxAttempts);
        $this->assertSame($ordinaryLimit->key, $usersLimit->key);
    }

    /**
     * Why the branch tests "holds ANY of the five" rather than "holds the one
     * THIS route needs".
     *
     * The seven routes gate on five different permissions, so there is no
     * single `imports:process` to check. Keying on one of them — `users:view`,
     * the obvious pick — would throttle a role built with `users:reset_password`
     * alone down to 10/min on the only endpoint it exists to use. Roles are
     * editable from the UI, so that role is a shape somebody can create this
     * afternoon.
     */
    public function test_a_caller_holding_any_single_user_permission_is_not_throttled(): void
    {
        $limiter = RateLimiter::limiter('api');

        foreach (['users:view', 'users:create', 'users:update', 'users:delete', 'users:reset_password'] as $permission) {
            $limit = $limiter($this->requestFor('users.show', $this->withPermission($permission)));

            $this->assertSame(60, $limit->maxAttempts, "A holder of [{$permission}] was thrown into the denied bucket.");
        }
    }

    /**
     * End to end, through the real middleware stack: the eleventh probe in a
     * minute is a 429 rather than another 404. Without this the assertions
     * above would all pass on a limiter nothing is wired to.
     */
    public function test_an_id_sweep_is_cut_off_after_ten_probes(): void
    {
        $collector = $this->withRole('collector');
        $target = User::factory()->create(['branch_id' => $this->branch->id]);

        $this->actingAs($collector);

        for ($i = 0; $i < 10; $i++) {
            $this->getJson("/api/users/{$target->id}")->assertNotFound();
        }

        $this->getJson("/api/users/{$target->id}")->assertTooManyRequests();

        // The same caller can still do their job: the denied bucket is its own
        // counter and has not touched the shared one.
        $this->getJson('/api/borrowers')->assertOk();
    }
}
