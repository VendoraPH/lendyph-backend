<?php

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Who may run a CSV migration.
 *
 * A migration import writes borrowers and released loans in bulk with no
 * approval workflow in front of them — the one path in this system that creates
 * debt nobody approved. That is a one-off data-migration power, so it sits with
 * the two roles that already answer for the state of the books and with nobody
 * else. `loan_officer` holds `loans:create` and `borrowers:create` and still
 * must not hold this.
 *
 * Both grant paths are covered, because they are genuinely different code:
 * a fresh database gets the permission from RoleAndPermissionSeeder, while
 * staging and production are already migrated and will never re-run a seeder —
 * they get it only from the migration. Testing one would leave the other free
 * to drift.
 */
uses(TestCase::class);

beforeEach(function () {
    Artisan::call('migrate:fresh');
    $this->seed(DatabaseSeeder::class);
});

function importsPermissionMigration(): object
{
    return require database_path('migrations/2026_08_29_100005_add_imports_process_permission.php');
}

function forgetPermissionCache(): void
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

it('creates the imports:process permission', function () {
    expect(Permission::where('name', 'imports:process')->where('guard_name', 'web')->exists())->toBeTrue();
});

it('grants imports:process to super_admin and admin', function (string $roleName) {
    expect(Role::where('name', $roleName)->firstOrFail()->hasPermissionTo('imports:process'))
        ->toBeTrue("Expected {$roleName} to hold imports:process.");
})->with(['super_admin', 'admin']);

it('withholds imports:process from every operational role', function (string $roleName) {
    expect(Role::where('name', $roleName)->firstOrFail()->hasPermissionTo('imports:process'))
        ->toBeFalse("{$roleName} must NOT hold imports:process — a bulk import creates released debt with no approval step.");
})->with(['loan_officer', 'cashier', 'collector', 'viewer']);

/**
 * The staging and production path.
 *
 * Those boxes already have their roles when the migration lands, so unlike a
 * fresh database the migration's own role-grant loop is what does the work. The
 * down()/up() cycle here reproduces exactly that ordering — roles first,
 * migration second — which `migrate:fresh` never exercises, because there
 * migrations run before any role exists and the grant loop finds nothing to
 * grant.
 */
it('grants the permission from the migration itself when the roles already exist', function () {
    importsPermissionMigration()->down();
    forgetPermissionCache();

    expect(Permission::where('name', 'imports:process')->exists())->toBeFalse();

    importsPermissionMigration()->up();
    forgetPermissionCache();

    expect(Permission::where('name', 'imports:process')->where('guard_name', 'web')->exists())->toBeTrue();

    foreach (['super_admin', 'admin'] as $roleName) {
        expect(Role::where('name', $roleName)->firstOrFail()->hasPermissionTo('imports:process'))
            ->toBeTrue("Expected the migration to grant imports:process to {$roleName} on an already-seeded box.");
    }

    foreach (['loan_officer', 'cashier', 'collector', 'viewer'] as $roleName) {
        expect(Role::where('name', $roleName)->firstOrFail()->hasPermissionTo('imports:process'))
            ->toBeFalse("The migration must not grant imports:process to {$roleName}.");
    }
});

/**
 * `updateOrInsert` plus existence-checked grants, so a re-run over a box that
 * already has the permission is a no-op rather than a failed deploy or a
 * duplicated pivot row.
 */
it('is safe to run twice', function () {
    importsPermissionMigration()->up();
    importsPermissionMigration()->up();
    forgetPermissionCache();

    expect(Permission::where('name', 'imports:process')->count())->toBe(1);

    $permissionId = Permission::where('name', 'imports:process')->value('id');
    $adminRoleId = Role::where('name', 'admin')->value('id');

    expect(DB::table('role_has_permissions')
        ->where('permission_id', $permissionId)
        ->where('role_id', $adminRoleId)
        ->count())->toBe(1);
});

/**
 * The seeder's canonical list has to carry it too. The migration alone would
 * leave a freshly built database depending on migration order for a permission
 * the seeder claims to define — the drift `collaterals:*` already has.
 */
it('lists imports:process in the seeder so a fresh database gets it without the migration', function () {
    $seeder = file_get_contents(database_path('seeders/RoleAndPermissionSeeder.php'));

    expect($seeder)->toContain("'imports:process'");
});
