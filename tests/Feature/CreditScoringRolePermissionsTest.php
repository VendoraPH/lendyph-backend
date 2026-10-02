<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The three Credit Scoring permissions the Settings → User Roles screen offers.
 *
 * Until they existed, ticking any of them made PUT /roles/{id} answer 422 and
 * refuse the whole save. They are granted to admin and super_admin only (owner's
 * decision, 2026-10-02), from the seeder on a fresh database and from
 * 2026_10_02_160000_add_credit_scoring_permissions on an already-migrated one.
 */
uses(TestCase::class);

function creditScoringPermissionsMigration(): object
{
    return require database_path('migrations/2026_10_02_160000_add_credit_scoring_permissions.php');
}

/**
 * @return list<string>
 */
function creditScoringGrants(string $roleName): array
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return Role::where('name', $roleName)->firstOrFail()
        ->permissions()->where('name', 'like', 'credit_scoring:%')->orderBy('name')->pluck('name')->all();
}

/**
 * Every seeded role other than the two administrators, keyed by name, with the
 * credit_scoring permissions each holds.
 *
 * @return array<string, list<string>>
 */
function creditScoringGrantsOfOtherRoles(): array
{
    return Role::whereNotIn('name', ['super_admin', 'admin'])->orderBy('name')->pluck('name')
        ->mapWithKeys(fn (string $roleName) => [$roleName => creditScoringGrants($roleName)])
        ->all();
}

/**
 * @return list<string>
 */
function creditScoringPermissionNames(): array
{
    return ['credit_scoring:override', 'credit_scoring:settings', 'credit_scoring:view'];
}

beforeEach(function () {
    $this->actingAs(User::where('username', 'super_admin')->first());
});

it('holds exactly the three credit scoring permissions the roles screen offers', function () {
    expect(Permission::where('name', 'like', 'credit_scoring:%')->orderBy('name')->pluck('name')->all())
        ->toBe(['credit_scoring:override', 'credit_scoring:settings', 'credit_scoring:view']);
});

it('creates them on the web guard', function () {
    expect(Permission::where('name', 'like', 'credit_scoring:%')->pluck('guard_name')->unique()->all())
        ->toBe(['web']);
});

it('grants all three to the administrators', function (string $roleName) {
    expect(creditScoringGrants($roleName))->toBe(creditScoringPermissionNames());
})->with(['super_admin', 'admin']);

it('grants none of them to any other seeded role', function () {
    $others = creditScoringGrantsOfOtherRoles();

    expect($others)->not->toBeEmpty()
        ->and(array_filter($others))->toBe([]);
});

it('saves a role with a credit scoring permission ticked', function () {
    $viewer = Role::where('name', 'viewer')->firstOrFail();
    $permissions = $viewer->permissions()->pluck('name')->push('credit_scoring:view')->all();

    $this->putJson("/api/roles/{$viewer->id}", ['permissions' => $permissions])
        ->assertOk();

    expect($viewer->fresh()->hasPermissionTo('credit_scoring:view'))->toBeTrue();
});

it('saves a role with every credit scoring tick and returns them', function () {
    $role = Role::create(['name' => 'credit_reviewer', 'guard_name' => 'web']);

    $response = $this->putJson("/api/roles/{$role->id}", [
        'permissions' => ['dashboard:view', ...creditScoringPermissionNames()],
    ])->assertOk();

    expect(collect($response->json('data.permissions'))->filter(
        fn (string $name) => str_starts_with($name, 'credit_scoring:'),
    )->sort()->values()->all())->toBe(creditScoringPermissionNames())
        ->and(creditScoringGrants('credit_reviewer'))->toBe(creditScoringPermissionNames());
});

it('seeds them on a fresh database, where the migration finds no roles to grant to', function () {
    creditScoringPermissionsMigration()->down();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(Permission::where('name', 'like', 'credit_scoring:%')->exists())->toBeFalse();

    $this->seed(RoleAndPermissionSeeder::class);

    expect(creditScoringGrants('super_admin'))->toBe(creditScoringPermissionNames())
        ->and(creditScoringGrants('admin'))->toBe(creditScoringPermissionNames())
        ->and(array_filter(creditScoringGrantsOfOtherRoles()))->toBe([]);
});

/**
 * The staging and production path: the roles already exist when the migration
 * lands, so its own grant loop does the work. `migrate:fresh` never exercises
 * that order, because there migrations run before any role exists.
 */
it('grants the administrators only, when it runs on a database whose roles already exist', function () {
    creditScoringPermissionsMigration()->down();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(Permission::where('name', 'like', 'credit_scoring:%')->exists())->toBeFalse();

    creditScoringPermissionsMigration()->up();

    expect(Permission::where('name', 'like', 'credit_scoring:%')->where('guard_name', 'web')->orderBy('name')->pluck('name')->all())
        ->toBe(creditScoringPermissionNames())
        ->and(creditScoringGrants('super_admin'))->toBe(creditScoringPermissionNames())
        ->and(creditScoringGrants('admin'))->toBe(creditScoringPermissionNames())
        ->and(array_filter(creditScoringGrantsOfOtherRoles()))->toBe([]);
});

it('is safe to run again', function () {
    creditScoringPermissionsMigration()->down();
    creditScoringPermissionsMigration()->up();

    $permissions = DB::table('permissions')->count();
    $grants = DB::table('role_has_permissions')->count();

    creditScoringPermissionsMigration()->up();

    expect(DB::table('permissions')->count())->toBe($permissions)
        ->and(DB::table('role_has_permissions')->count())->toBe($grants)
        ->and(creditScoringGrants('admin'))->toBe(creditScoringPermissionNames());
});

it('never removes a grant an admin made through the roles screen', function () {
    Role::where('name', 'viewer')->firstOrFail()->givePermissionTo('credit_scoring:view');

    creditScoringPermissionsMigration()->up();

    expect(creditScoringGrants('viewer'))->toBe(['credit_scoring:view']);
});

it('skips a role that does not exist on this box', function () {
    creditScoringPermissionsMigration()->down();
    Role::where('name', 'admin')->firstOrFail()->delete();

    creditScoringPermissionsMigration()->up();

    expect(Role::where('name', 'admin')->exists())->toBeFalse()
        ->and(creditScoringGrants('super_admin'))->toBe(creditScoringPermissionNames());
});
