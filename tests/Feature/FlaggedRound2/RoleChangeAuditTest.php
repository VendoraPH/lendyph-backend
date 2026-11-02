<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\RolePermissionAudit;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Role changes write no audit row today. Pinned shape (plan, decision 1):
 * the Role model records `created`, `updated` and `deleted` rows like every
 * other auditable model (who, which role, old and new columns), and a change
 * to a role's permissions writes one `role_permissions_changed` row against the
 * role carrying the sorted old and new lists. A save that leaves the list as it
 * was writes no permission row.
 *
 * Assigning or removing a user's role is already audited (`role_assigned`,
 * `role_changed`; see UserManagementTest), so it is not repeated here.
 */

beforeEach(function () {
    $this->actor = User::where('username', 'super_admin')->firstOrFail();
    $this->actingAs($this->actor);
});

function flaggedRoleAuditRows(Role $role, string $action)
{
    return AuditLog::query()
        ->where('action', $action)
        ->where('auditable_type', $role->getMorphClass())
        ->where('auditable_id', $role->id)
        ->orderBy('id')
        ->get();
}

it('writes a created row and a permission row when a role is created', function () {
    $this->postJson('/api/roles', [
        'name' => 'branch_auditor',
        'display_name' => 'Branch Auditor',
        'permissions' => ['loans:view', 'dashboard:view'],
    ])->assertCreated();

    $role = Role::where('name', 'branch_auditor')->firstOrFail();

    $created = flaggedRoleAuditRows($role, 'created');
    expect($created)->toHaveCount(1);
    expect($created->first()->user_id)->toBe($this->actor->id);
    expect($created->first()->new_values['name'])->toBe('branch_auditor');
    expect($created->first()->new_values['display_name'])->toBe('Branch Auditor');

    $permissions = flaggedRoleAuditRows($role, 'role_permissions_changed');
    expect($permissions)->toHaveCount(1);
    expect($permissions->first()->user_id)->toBe($this->actor->id);
    expect($permissions->first()->old_values)->toBe(['permissions' => []]);
    expect($permissions->first()->new_values)->toBe(['permissions' => ['dashboard:view', 'loans:view']]);
});

it('records the old and new display_name when a role is relabelled', function () {
    $cashier = Role::where('name', 'cashier')->firstOrFail();

    $this->putJson("/api/roles/{$cashier->id}", ['display_name' => 'Teller'])->assertSuccessful();

    $updated = flaggedRoleAuditRows($cashier, 'updated');
    expect($updated)->toHaveCount(1);
    expect($updated->first()->user_id)->toBe($this->actor->id);
    expect($updated->first()->old_values['display_name'])->toBeNull();
    expect($updated->first()->new_values['display_name'])->toBe('Teller');
});

it('records the old and new permission lists when a role\'s permissions change, and nothing when they do not', function () {
    $role = Role::create(['name' => 'cash_desk', 'guard_name' => 'web', 'is_system' => false]);
    $role->syncPermissions(['dashboard:view']);

    $this->putJson("/api/roles/{$role->id}", [
        'permissions' => ['payments:view', 'dashboard:view'],
    ])->assertSuccessful();

    $rows = flaggedRoleAuditRows($role, 'role_permissions_changed');
    expect($rows)->toHaveCount(1);
    expect($rows->first()->user_id)->toBe($this->actor->id);
    expect($rows->first()->old_values)->toBe(['permissions' => ['dashboard:view']]);
    expect($rows->first()->new_values)->toBe(['permissions' => ['dashboard:view', 'payments:view']]);

    // The same list again, and a description-only edit: no further permission row.
    $this->putJson("/api/roles/{$role->id}", [
        'permissions' => ['dashboard:view', 'payments:view'],
    ])->assertSuccessful();
    $this->putJson("/api/roles/{$role->id}", ['description' => 'Front desk'])->assertSuccessful();

    expect(flaggedRoleAuditRows($role, 'role_permissions_changed'))->toHaveCount(1);
});

it('records deactivating and reactivating a role', function () {
    $role = Role::create(['name' => 'night_shift', 'guard_name' => 'web', 'is_system' => false, 'is_active' => true]);

    $this->patchJson("/api/roles/{$role->id}/deactivate")->assertSuccessful();
    $this->patchJson("/api/roles/{$role->id}/reactivate")->assertSuccessful();

    $rows = flaggedRoleAuditRows($role, 'updated');
    expect($rows)->toHaveCount(2);
    expect((bool) $rows[0]->new_values['is_active'])->toBeFalse();
    expect((bool) $rows[1]->new_values['is_active'])->toBeTrue();
    expect($rows->pluck('user_id')->unique()->all())->toBe([$this->actor->id]);
});

it('writes a deleted row when a custom role is deleted', function () {
    $role = Role::create(['name' => 'temp_reviewer', 'guard_name' => 'web', 'is_system' => false]);
    $roleId = $role->id;

    $this->deleteJson("/api/roles/{$roleId}")->assertSuccessful();

    $deleted = AuditLog::query()
        ->where('action', 'deleted')
        ->where('auditable_type', $role->getMorphClass())
        ->where('auditable_id', $roleId)
        ->get();

    expect($deleted)->toHaveCount(1);
    expect($deleted->first()->user_id)->toBe($this->actor->id);
    expect($deleted->first()->old_values['name'])->toBe('temp_reviewer');
});

it('writes no audit rows when the role seeder runs', function () {
    // Drift the seeded baseline so the seeder has something to write back.
    DB::table('roles')->where('name', 'cashier')->update(['description' => 'Drifted']);
    $before = AuditLog::where('auditable_type', (new Role)->getMorphClass())->count();

    $this->seed(RoleAndPermissionSeeder::class);

    expect(Role::where('name', 'cashier')->value('description'))->not->toBe('Drifted');
    expect(AuditLog::where('auditable_type', (new Role)->getMorphClass())->count())->toBe($before);
});

it('writes one system row, with no user, when a migration changes a role\'s permissions', function () {
    Auth::forgetGuards();

    // A migration loads the app's Role, the class config('permission.models.role') names.
    $viewer = Role::findByName('viewer', 'web');
    $before = RolePermissionAudit::names($viewer);
    $viewer->givePermissionTo('settings:delete');
    RolePermissionAudit::record($viewer, $before, RolePermissionAudit::names($viewer), 'System change: test migration grants settings:delete to viewer');

    // Unchanged: no second row.
    RolePermissionAudit::record($viewer, RolePermissionAudit::names($viewer), RolePermissionAudit::names($viewer));

    $rows = flaggedRoleAuditRows(Role::findOrFail($viewer->id), 'role_permissions_changed');
    expect($rows)->toHaveCount(1);
    expect($rows->first()->user_id)->toBeNull();
    expect($rows->first()->description)->toBe('System change: test migration grants settings:delete to viewer');
    expect($rows->first()->old_values['permissions'])->not->toContain('settings:delete');
    expect($rows->first()->new_values['permissions'])->toContain('settings:delete');
});

it('records the permissions a deleted role carried', function () {
    $role = Role::create(['name' => 'temp_auditor', 'guard_name' => 'web', 'is_system' => false]);
    $role->syncPermissions(['loans:view', 'dashboard:view']);

    $this->deleteJson("/api/roles/{$role->id}")->assertSuccessful();

    $rows = AuditLog::query()
        ->where('action', 'role_permissions_changed')
        ->where('auditable_type', $role->getMorphClass())
        ->where('auditable_id', $role->id)
        ->get();

    expect($rows)->toHaveCount(1);
    expect($rows->first()->user_id)->toBe($this->actor->id);
    expect($rows->first()->old_values)->toBe(['permissions' => ['dashboard:view', 'loans:view']]);
    expect($rows->first()->new_values)->toBe(['permissions' => []]);
});
