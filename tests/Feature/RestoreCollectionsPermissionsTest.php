<?php

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The migration that gives back the collections:* grants the roles screen
 * stripped on every save.
 *
 * Staging and production already have their roles when it lands, so each test
 * first puts a role into the state the bug left it in, then runs up() the way
 * a deploy would.
 */
uses(TestCase::class);

function restoreCollectionsMigration(): object
{
    return require database_path('migrations/2026_09_30_100000_restore_collections_permissions.php');
}

function collectionsGrants(string $roleName): array
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return Role::where('name', $roleName)->firstOrFail()
        ->permissions()->where('name', 'like', 'collections:%')->orderBy('name')->pluck('name')->all();
}

/** What a save through the old roles screen did to a role. */
function stripCollectionsFrom(string $roleName): void
{
    $role = Role::where('name', $roleName)->firstOrFail();
    $role->syncPermissions($role->permissions()->where('name', 'not like', 'collections:%')->pluck('name')->all());
}

it('restores the seeded collections grants to the roles that lost them', function () {
    foreach (['super_admin', 'admin', 'collector', 'loan_officer'] as $roleName) {
        stripCollectionsFrom($roleName);
    }

    restoreCollectionsMigration()->up();

    expect(collectionsGrants('super_admin'))->toBe(['collections:mark_collected', 'collections:view'])
        ->and(collectionsGrants('admin'))->toBe(['collections:mark_collected', 'collections:view'])
        ->and(collectionsGrants('collector'))->toBe(['collections:mark_collected', 'collections:view'])
        ->and(collectionsGrants('loan_officer'))->toBe(['collections:view']);
});

it('leaves every other permission of a restored role as it was', function () {
    stripCollectionsFrom('admin');
    $before = Role::where('name', 'admin')->firstOrFail()->permissions()->pluck('name')->sort()->values()->all();

    restoreCollectionsMigration()->up();

    $after = Role::where('name', 'admin')->firstOrFail()->permissions()->pluck('name')->sort()->values()->all();
    expect(array_values(array_diff($after, $before)))->toBe(['collections:mark_collected', 'collections:view'])
        ->and(array_diff($before, $after))->toBe([]);
});

it('gives nothing to roles the seeder never granted collections to', function () {
    restoreCollectionsMigration()->up();

    foreach (['cashier', 'viewer', 'general_bookkeeper', 'manager'] as $roleName) {
        expect(collectionsGrants($roleName))->toBe([], "{$roleName} must not gain collections:*.");
    }
});

it('never removes a grant, even one outside the restore list', function () {
    Role::where('name', 'viewer')->firstOrFail()->givePermissionTo('collections:view');

    restoreCollectionsMigration()->up();

    expect(collectionsGrants('viewer'))->toBe(['collections:view']);
});

it('is safe to run again', function () {
    stripCollectionsFrom('admin');

    restoreCollectionsMigration()->up();
    $rows = DB::table('role_has_permissions')->count();
    restoreCollectionsMigration()->up();

    expect(DB::table('role_has_permissions')->count())->toBe($rows)
        ->and(collectionsGrants('admin'))->toBe(['collections:mark_collected', 'collections:view']);
});

it('skips a role that does not exist on this box', function () {
    Role::where('name', 'collector')->firstOrFail()->delete();

    restoreCollectionsMigration()->up();

    expect(Role::where('name', 'collector')->exists())->toBeFalse()
        ->and(collectionsGrants('admin'))->toBe(['collections:mark_collected', 'collections:view']);
});
