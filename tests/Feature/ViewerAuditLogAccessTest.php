<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {});

it('does not grant a fresh viewer role audit access', function () {
    expect(Role::findByName('viewer', 'web')->hasPermissionTo('audit_logs:view'))->toBeFalse();
});

it('refuses a viewer the audit log over http', function () {
    $viewer = User::factory()->create(['branch_id' => 1, 'status' => 'active']);
    $viewer->syncRoles(['viewer']);

    $this->actingAs($viewer)->getJson('/api/audit-logs')->assertForbidden();
});

it('still lets admin read the audit log', function () {
    // The revoke must be surgical: only `viewer` loses it.
    $admin = User::where('username', 'super_admin')->first();

    $this->actingAs($admin)->getJson('/api/audit-logs')->assertOk();

    expect(Role::findByName('admin', 'web')->hasPermissionTo('audit_logs:view'))->toBeTrue();
});

/**
 * The part that actually matters for the fleet.
 *
 * Editing the seeder changes nothing on a box that has already run it, and all
 * five deployments have. This re-creates that exact state — a viewer role that
 * HOLDS the grant, as if seeded by the old code — and then runs the migration
 * over it, which is the only thing that will touch those boxes.
 */
it('revokes the grant from an already-seeded installation', function () {
    $role = Role::findByName('viewer', 'web');
    $permission = Permission::findByName('audit_logs:view', 'web');

    // Put the box back into the pre-fix state.
    DB::table('role_has_permissions')->insert([
        'role_id' => $role->id,
        'permission_id' => $permission->id,
    ]);
    DB::table('roles')->where('id', $role->id)
        ->update(['description' => 'Read-only access to operational data and audit logs.']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(Role::findByName('viewer', 'web')->hasPermissionTo('audit_logs:view'))->toBeTrue();

    // Now run the one-shot the deployments will run.
    $migration = require database_path('migrations/2026_09_18_200001_revoke_audit_logs_view_from_viewer.php');
    $migration->up();

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(Role::findByName('viewer', 'web')->hasPermissionTo('audit_logs:view'))->toBeFalse()
        ->and(DB::table('roles')->where('id', $role->id)->value('description'))
        ->toBe('Read-only access to operational data.');

    // And a viewer is refused in practice, not just in the pivot table.
    $viewer = User::factory()->create(['branch_id' => 1, 'status' => 'active']);
    $viewer->syncRoles(['viewer']);
    $this->actingAs($viewer)->getJson('/api/audit-logs')->assertForbidden();
});

it('is reversible', function () {
    $migration = require database_path('migrations/2026_09_18_200001_revoke_audit_logs_view_from_viewer.php');

    $migration->down();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect(Role::findByName('viewer', 'web')->hasPermissionTo('audit_logs:view'))->toBeTrue();

    $migration->up();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect(Role::findByName('viewer', 'web')->hasPermissionTo('audit_logs:view'))->toBeFalse();
});

it('leaves a reworded description alone', function () {
    // Operators can rename and re-describe roles in the UI. The migration only
    // rewrites the description if it still reads exactly as seeded.
    $role = Role::findByName('viewer', 'web');
    DB::table('roles')->where('id', $role->id)->update(['description' => 'Our own wording.']);

    $migration = require database_path('migrations/2026_09_18_200001_revoke_audit_logs_view_from_viewer.php');
    $migration->up();

    expect(DB::table('roles')->where('id', $role->id)->value('description'))->toBe('Our own wording.');
});
