<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Cic\CicAccess;
use App\Services\PermissionWords;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class);

/*
 * The five `cic:*` permissions (the CIC build plan, decision 8): super_admin
 * and admin only, granted on migrated deployments by a migration that mirrors
 * the seeder, with one system audit row per role whose list changes, and read
 * in words.
 */

const CIC_PERMISSION_MIGRATION = '2026_11_28_900100_add_cic_permissions';

function cicPermissionMigration(): object
{
    return require database_path('migrations/'.CIC_PERMISSION_MIGRATION.'.php');
}

/** @return array<string, list<string>> each cic permission and the roles holding it */
function cicPermissionHolders(): array
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $holders = [];

    foreach (CicAccess::PERMISSIONS as $permission) {
        $holders[$permission] = Role::query()->orderBy('name')->get()
            ->filter(fn (Role $role) => $role->permissions()->where('name', $permission)->exists())
            ->pluck('name')->values()->all();
    }

    return $holders;
}

function everyCicPermissionHeldBy(array $roles): array
{
    return array_fill_keys(CicAccess::PERMISSIONS, $roles);
}

beforeEach(function () {
    $this->actingAs(User::where('username', 'super_admin')->first());
});

it('is five web permissions, held by the administrators only', function () {
    expect(Permission::query()->where('name', 'like', 'cic:%')->orderBy('name')->pluck('name')->all())
        ->toBe(['cic:prepare', 'cic:review', 'cic:settings', 'cic:submit', 'cic:view'])
        ->and(CicAccess::PERMISSIONS)->toBe(['cic:view', 'cic:prepare', 'cic:submit', 'cic:review', 'cic:settings'])
        ->and(cicPermissionHolders())->toBe(everyCicPermissionHeldBy(['admin', 'super_admin']));
});

it('reads in words on the server', function () {
    expect(PermissionWords::of('cic:view'))->toBe('CIC Reporting: View')
        ->and(PermissionWords::of('cic:prepare'))->toBe('CIC Reporting: Prepare')
        ->and(PermissionWords::of('cic:settings'))->toBe('CIC Reporting: Settings');
});

it('seeds them on a fresh database, where the migration finds no roles to grant to', function () {
    cicPermissionMigration()->down();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(Permission::query()->where('name', 'like', 'cic:%')->exists())->toBeFalse();

    $this->seed(RoleAndPermissionSeeder::class);

    expect(cicPermissionHolders())->toBe(everyCicPermissionHeldBy(['admin', 'super_admin']));
});

it('grants the administrators, with one system audit row each, when the roles already exist', function () {
    cicPermissionMigration()->down();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Auth::logout();
    $before = AuditLog::query()->max('id') ?? 0;

    cicPermissionMigration()->up();

    $rows = AuditLog::query()->where('id', '>', $before)->get();

    expect(cicPermissionHolders())->toBe(everyCicPermissionHeldBy(['admin', 'super_admin']))
        ->and($rows)->toHaveCount(2)
        ->and($rows->pluck('action')->unique()->values()->all())->toBe(['role_permissions_changed'])
        ->and($rows->pluck('user_id')->unique()->values()->all())->toBe([null]);

    foreach ($rows as $row) {
        expect((string) $row->description)->toContain(CIC_PERMISSION_MIGRATION)
            ->and((string) $row->description)->toContain('CIC Reporting');
    }
});

it('is safe to run again, writing no audit row', function () {
    cicPermissionMigration()->down();
    cicPermissionMigration()->up();

    $counts = [DB::table('permissions')->count(), DB::table('role_has_permissions')->count(), AuditLog::query()->count()];

    cicPermissionMigration()->up();

    expect([DB::table('permissions')->count(), DB::table('role_has_permissions')->count(), AuditLog::query()->count()])->toBe($counts);
});

it('is never handed out by someone who doesn\'t hold it', function () {
    $desk = Role::query()->create(['name' => 'role_desk', 'guard_name' => 'web', 'is_active' => true]);
    $desk->givePermissionTo(['settings:view', 'settings:update', 'dashboard:view']);
    $actor = User::factory()->create();
    $actor->assignRole($desk);
    $target = Role::query()->create(['name' => 'desk_target', 'guard_name' => 'web', 'is_active' => true]);
    $target->givePermissionTo(['dashboard:view']);

    $this->actingAs($actor)
        ->putJson("/api/roles/{$target->id}", ['permissions' => ['dashboard:view', 'cic:view']])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['permissions' => 'CIC Reporting: View']);

    expect($target->fresh()->hasPermissionTo('cic:view'))->toBeFalse();
});
