<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * `linda:use` (ask Linda) and `linda:settings` (Settings → Linda): admin and
 * super_admin only, from the seeder on a fresh database and from
 * 2026_11_16_100000_add_linda_permissions on an already-migrated one, with one
 * system audit row per role granted and nothing on a re-run.
 */
uses(TestCase::class);

const LINDA_PERMISSIONS_MIGRATION = '2026_11_16_100000_add_linda_permissions';

function lindaPermissionsMigration(): object
{
    return require database_path('migrations/'.LINDA_PERMISSIONS_MIGRATION.'.php');
}

/** @return list<string> */
function lindaPermissionNames(): array
{
    return ['linda:settings', 'linda:use'];
}

/** @return list<string> */
function lindaGrants(string $roleName): array
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return Role::where('name', $roleName)->firstOrFail()
        ->permissions()->where('name', 'like', 'linda:%')->orderBy('name')->pluck('name')->all();
}

/** @return array<string, list<string>> */
function lindaGrantsOfOtherRoles(): array
{
    return Role::whereNotIn('name', ['super_admin', 'admin'])->orderBy('name')->pluck('name')
        ->mapWithKeys(fn (string $roleName) => [$roleName => lindaGrants($roleName)])
        ->all();
}

beforeEach(function () {
    $this->actingAs(User::where('username', 'super_admin')->first());
});

it('holds exactly the two Linda permissions', function () {
    expect(Permission::where('name', 'like', 'linda:%')->orderBy('name')->pluck('name')->all())
        ->toBe(lindaPermissionNames())
        ->and(Permission::where('name', 'like', 'linda:%')->pluck('guard_name')->unique()->all())->toBe(['web']);
});

it('grants both to the administrators', function (string $roleName) {
    expect(lindaGrants($roleName))->toBe(lindaPermissionNames());
})->with(['super_admin', 'admin']);

it('grants neither to any other seeded role', function () {
    $others = lindaGrantsOfOtherRoles();

    expect($others)->not->toBeEmpty()
        ->and(array_filter($others))->toBe([]);
});

it('saves a role with a Linda permission ticked', function () {
    $viewer = Role::where('name', 'viewer')->firstOrFail();
    $permissions = $viewer->permissions()->pluck('name')->push('linda:use')->all();

    $this->putJson("/api/roles/{$viewer->id}", ['permissions' => $permissions])->assertOk();

    expect($viewer->fresh()->hasPermissionTo('linda:use'))->toBeTrue();
});

it('seeds them on a fresh database, where the migration finds no roles to grant to', function () {
    lindaPermissionsMigration()->down();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(Permission::where('name', 'like', 'linda:%')->exists())->toBeFalse();

    $this->seed(RoleAndPermissionSeeder::class);

    expect(lindaGrants('super_admin'))->toBe(lindaPermissionNames())
        ->and(lindaGrants('admin'))->toBe(lindaPermissionNames())
        ->and(array_filter(lindaGrantsOfOtherRoles()))->toBe([]);
});

it('grants the administrators only, with one system audit row each, when the roles already exist', function () {
    lindaPermissionsMigration()->down();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Auth::logout();
    $before = AuditLog::query()->max('id') ?? 0;

    lindaPermissionsMigration()->up();

    $rows = AuditLog::query()->where('id', '>', $before)->get();

    expect(lindaGrants('super_admin'))->toBe(lindaPermissionNames())
        ->and(lindaGrants('admin'))->toBe(lindaPermissionNames())
        ->and(array_filter(lindaGrantsOfOtherRoles()))->toBe([])
        ->and($rows)->toHaveCount(2)
        ->and($rows->pluck('action')->unique()->values()->all())->toBe(['role_permissions_changed'])
        ->and($rows->pluck('user_id')->unique()->values()->all())->toBe([null]);

    foreach ($rows as $row) {
        expect((string) $row->description)->toContain(LINDA_PERMISSIONS_MIGRATION);
    }
});

it('is safe to run again, writing no audit row', function () {
    lindaPermissionsMigration()->down();
    lindaPermissionsMigration()->up();

    $counts = [DB::table('permissions')->count(), DB::table('role_has_permissions')->count(), AuditLog::query()->count()];

    lindaPermissionsMigration()->up();

    expect([DB::table('permissions')->count(), DB::table('role_has_permissions')->count(), AuditLog::query()->count()])->toBe($counts)
        ->and(lindaGrants('admin'))->toBe(lindaPermissionNames());
});

it('never removes a grant an admin made through the roles screen', function () {
    Role::where('name', 'viewer')->firstOrFail()->givePermissionTo('linda:use');

    lindaPermissionsMigration()->up();

    expect(lindaGrants('viewer'))->toBe(['linda:use']);
});

it('skips a role that does not exist on this box', function () {
    lindaPermissionsMigration()->down();
    Role::where('name', 'admin')->firstOrFail()->delete();

    lindaPermissionsMigration()->up();

    expect(Role::where('name', 'admin')->exists())->toBeFalse()
        ->and(lindaGrants('super_admin'))->toBe(lindaPermissionNames());
});
