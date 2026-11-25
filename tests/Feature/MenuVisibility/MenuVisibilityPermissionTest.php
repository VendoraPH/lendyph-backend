<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionWords;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class);

/*
 * `menu_visibility:manage` (finish run round 6): super_admin and admin only,
 * granted on migrated deployments by a migration that mirrors the seeder, with
 * one system audit row per role whose list changes, and under the grant guard
 * like every other permission.
 */

const MENU_VISIBILITY_PERMISSION_MIGRATION = '2026_11_25_700100_add_menu_visibility_manage_permission';

function menuVisibilityPermissionMigration(): object
{
    return require database_path('migrations/'.MENU_VISIBILITY_PERMISSION_MIGRATION.'.php');
}

function menuVisibilityHolders(): array
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return Role::query()->orderBy('name')->get()
        ->filter(fn (Role $role) => $role->permissions()->where('name', 'menu_visibility:manage')->exists())
        ->pluck('name')->values()->all();
}

beforeEach(function () {
    $this->actingAs(User::where('username', 'super_admin')->first());
});

it('is one web permission, held by the administrators only', function () {
    expect(Permission::query()->where('name', 'like', 'menu_visibility:%')->pluck('name')->all())->toBe(['menu_visibility:manage'])
        ->and(Permission::findByName('menu_visibility:manage', 'web'))->not->toBeNull()
        ->and(menuVisibilityHolders())->toBe(['admin', 'super_admin']);
});

it('reads in words on the server', function () {
    expect(PermissionWords::of('menu_visibility:manage'))->toBe('Menu Visibility: Manage');
});

it('seeds it on a fresh database, where the migration finds no roles to grant to', function () {
    menuVisibilityPermissionMigration()->down();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(Permission::query()->where('name', 'menu_visibility:manage')->exists())->toBeFalse();

    $this->seed(RoleAndPermissionSeeder::class);

    expect(menuVisibilityHolders())->toBe(['admin', 'super_admin']);
});

it('grants the administrators, with one system audit row each, when the roles already exist', function () {
    menuVisibilityPermissionMigration()->down();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Auth::logout();
    $before = AuditLog::query()->max('id') ?? 0;

    menuVisibilityPermissionMigration()->up();

    $rows = AuditLog::query()->where('id', '>', $before)->get();

    expect(menuVisibilityHolders())->toBe(['admin', 'super_admin'])
        ->and($rows)->toHaveCount(2)
        ->and($rows->pluck('action')->unique()->values()->all())->toBe(['role_permissions_changed'])
        ->and($rows->pluck('user_id')->unique()->values()->all())->toBe([null]);

    foreach ($rows as $row) {
        expect((string) $row->description)->toContain(MENU_VISIBILITY_PERMISSION_MIGRATION);
    }
});

it('is safe to run again, writing no audit row', function () {
    menuVisibilityPermissionMigration()->down();
    menuVisibilityPermissionMigration()->up();

    $counts = [DB::table('permissions')->count(), DB::table('role_has_permissions')->count(), AuditLog::query()->count()];

    menuVisibilityPermissionMigration()->up();

    expect([DB::table('permissions')->count(), DB::table('role_has_permissions')->count(), AuditLog::query()->count()])->toBe($counts);
});

it('can be ticked on another role by someone who holds it', function () {
    $viewer = Role::where('name', 'viewer')->firstOrFail();
    $permissions = $viewer->permissions()->pluck('name')->push('menu_visibility:manage')->all();

    $this->putJson("/api/roles/{$viewer->id}", ['permissions' => $permissions])->assertOk();

    expect($viewer->fresh()->hasPermissionTo('menu_visibility:manage'))->toBeTrue();
});

it('is never handed out by someone who doesn\'t hold it', function () {
    $desk = Role::query()->create(['name' => 'role_desk', 'guard_name' => 'web', 'is_active' => true]);
    $desk->givePermissionTo(['settings:view', 'settings:update', 'dashboard:view']);
    $actor = User::factory()->create();
    $actor->assignRole($desk);
    $target = Role::query()->create(['name' => 'desk_target', 'guard_name' => 'web', 'is_active' => true]);
    $target->givePermissionTo(['dashboard:view']);

    $this->actingAs($actor)
        ->putJson("/api/roles/{$target->id}", ['permissions' => ['dashboard:view', 'menu_visibility:manage']])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['permissions' => 'Menu Visibility: Manage']);

    expect($target->fresh()->hasPermissionTo('menu_visibility:manage'))->toBeFalse();
});
