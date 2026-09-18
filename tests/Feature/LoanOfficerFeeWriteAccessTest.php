<?php

use App\Models\Fee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class);

const REVOKED_FEE_PERMISSIONS = ['fees:create', 'fees:update', 'fees:delete'];

const FEE_REVOKE_MIGRATION = 'migrations/2026_09_19_090000_revoke_fee_write_from_loan_officer.php';

/**
 * Put the box back into the pre-fix state: a `loan_officer` role that HOLDS
 * all three write permissions, exactly as the old seeder left the five
 * deployments.
 */
function grantFeeWriteToLoanOfficer(): void
{
    $roleId = Role::findByName('loan_officer', 'web')->id;

    foreach (REVOKED_FEE_PERMISSIONS as $name) {
        $permissionId = Permission::findByName($name, 'web')->id;

        $exists = DB::table('role_has_permissions')
            ->where('role_id', $roleId)
            ->where('permission_id', $permissionId)
            ->exists();

        if (! $exists) {
            DB::table('role_has_permissions')->insert([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

function loanOfficerUser(): User
{
    $user = User::factory()->create(['branch_id' => 1, 'status' => 'active']);
    $user->syncRoles(['loan_officer']);

    return $user;
}

it('does not grant a fresh loan_officer role fee write access', function () {
    $role = Role::findByName('loan_officer', 'web');

    expect($role->hasPermissionTo('fees:create'))->toBeFalse()
        ->and($role->hasPermissionTo('fees:update'))->toBeFalse()
        ->and($role->hasPermissionTo('fees:delete'))->toBeFalse();
});

it('keeps fees:view on loan_officer', function () {
    // The whole point of the change: read access is retained.
    expect(Role::findByName('loan_officer', 'web')->hasPermissionTo('fees:view'))->toBeTrue();
});

it('refuses a loan officer fee writes over http', function () {
    $fee = Fee::factory()->create();
    $officer = loanOfficerUser();

    $this->actingAs($officer)
        ->postJson('/api/fees', ['name' => 'Officer Fee', 'type' => 'fixed', 'value' => 100])
        ->assertForbidden();

    $this->actingAs($officer)
        ->putJson("/api/fees/{$fee->id}", ['name' => 'Renamed', 'value' => 250])
        ->assertForbidden();

    $this->actingAs($officer)
        ->deleteJson("/api/fees/{$fee->id}")
        ->assertForbidden();

    // Nothing was written or destroyed on the way to those 403s.
    $this->assertDatabaseMissing('fees', ['name' => 'Officer Fee']);
    $this->assertDatabaseHas('fees', ['id' => $fee->id, 'name' => $fee->name]);
});

it('still lets a loan officer read fees over http', function () {
    $fee = Fee::factory()->create();
    $officer = loanOfficerUser();

    $this->actingAs($officer)->getJson('/api/fees')->assertOk();
    $this->actingAs($officer)->getJson("/api/fees/{$fee->id}")->assertOk();
});

it('still lets admin write fees', function () {
    // The revoke must be surgical: only `loan_officer` loses it.
    $role = Role::findByName('admin', 'web');

    expect($role->hasPermissionTo('fees:view'))->toBeTrue()
        ->and($role->hasPermissionTo('fees:create'))->toBeTrue()
        ->and($role->hasPermissionTo('fees:update'))->toBeTrue()
        ->and($role->hasPermissionTo('fees:delete'))->toBeTrue();

    $admin = User::factory()->create(['branch_id' => 1, 'status' => 'active']);
    $admin->syncRoles(['admin']);

    $this->actingAs($admin)
        ->postJson('/api/fees', ['name' => 'Admin Fee', 'type' => 'fixed', 'value' => 100])
        ->assertCreated();
});

/**
 * The part that actually matters for the fleet.
 *
 * Editing the seeder changes nothing on a box that has already run it, and all
 * five deployments have — `role_has_permissions` on every one of them still
 * carries these three rows. This re-creates that exact state and then runs the
 * migration over it, which is the only thing that will touch those boxes.
 */
it('revokes the grant from an already-seeded installation', function () {
    grantFeeWriteToLoanOfficer();

    $role = Role::findByName('loan_officer', 'web');
    expect($role->hasPermissionTo('fees:create'))->toBeTrue()
        ->and($role->hasPermissionTo('fees:update'))->toBeTrue()
        ->and($role->hasPermissionTo('fees:delete'))->toBeTrue();

    // Now run the one-shot the deployments will run.
    $migration = require database_path(FEE_REVOKE_MIGRATION);
    $migration->up();

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $role = Role::findByName('loan_officer', 'web');
    expect($role->hasPermissionTo('fees:create'))->toBeFalse()
        ->and($role->hasPermissionTo('fees:update'))->toBeFalse()
        ->and($role->hasPermissionTo('fees:delete'))->toBeFalse()
        // Read access survives the migration.
        ->and($role->hasPermissionTo('fees:view'))->toBeTrue();

    // And a loan officer is refused in practice, not just in the pivot table.
    $fee = Fee::factory()->create();
    $officer = loanOfficerUser();

    $this->actingAs($officer)
        ->postJson('/api/fees', ['name' => 'Officer Fee', 'type' => 'fixed', 'value' => 100])
        ->assertForbidden();
    $this->actingAs($officer)
        ->putJson("/api/fees/{$fee->id}", ['name' => 'Renamed', 'value' => 250])
        ->assertForbidden();
    $this->actingAs($officer)
        ->deleteJson("/api/fees/{$fee->id}")
        ->assertForbidden();

    // ...while still being able to read them.
    $this->actingAs($officer)->getJson('/api/fees')->assertOk();
});

it('leaves the permissions themselves in place for other roles', function () {
    grantFeeWriteToLoanOfficer();

    $migration = require database_path(FEE_REVOKE_MIGRATION);
    $migration->up();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // The migration deletes pivot rows, never the permission records.
    foreach (REVOKED_FEE_PERMISSIONS as $name) {
        expect(Permission::findByName($name, 'web'))->not->toBeNull();
    }

    expect(Role::findByName('admin', 'web')->hasPermissionTo('fees:create'))->toBeTrue();

    // Every other role that reads fees is untouched, and none ever wrote them.
    foreach (['cashier', 'collector', 'viewer', 'general_bookkeeper'] as $roleName) {
        $role = Role::findByName($roleName, 'web');

        expect($role->hasPermissionTo('fees:view'))->toBeTrue()
            ->and($role->hasPermissionTo('fees:create'))->toBeFalse()
            ->and($role->hasPermissionTo('fees:update'))->toBeFalse()
            ->and($role->hasPermissionTo('fees:delete'))->toBeFalse();
    }
});

it('is reversible', function () {
    $migration = require database_path(FEE_REVOKE_MIGRATION);

    $migration->down();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $role = Role::findByName('loan_officer', 'web');
    expect($role->hasPermissionTo('fees:create'))->toBeTrue()
        ->and($role->hasPermissionTo('fees:update'))->toBeTrue()
        ->and($role->hasPermissionTo('fees:delete'))->toBeTrue();

    $migration->up();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $role = Role::findByName('loan_officer', 'web');
    expect($role->hasPermissionTo('fees:create'))->toBeFalse()
        ->and($role->hasPermissionTo('fees:update'))->toBeFalse()
        ->and($role->hasPermissionTo('fees:delete'))->toBeFalse();
});

it('rolls back safely when the grant is only partly present', function () {
    // A box could sit in a half-state — e.g. an operator already pruned one of
    // the three by hand. `down()` must re-grant the rest without tripping the
    // pivot's composite primary key.
    $roleId = Role::findByName('loan_officer', 'web')->id;
    $permissionId = Permission::findByName('fees:update', 'web')->id;

    DB::table('role_has_permissions')->insert([
        'role_id' => $roleId,
        'permission_id' => $permissionId,
    ]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $migration = require database_path(FEE_REVOKE_MIGRATION);
    $migration->down();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $role = Role::findByName('loan_officer', 'web');
    expect($role->hasPermissionTo('fees:create'))->toBeTrue()
        ->and($role->hasPermissionTo('fees:update'))->toBeTrue()
        ->and($role->hasPermissionTo('fees:delete'))->toBeTrue();

    // And exactly one row per permission — no duplicates.
    foreach (REVOKED_FEE_PERMISSIONS as $name) {
        expect(
            DB::table('role_has_permissions')
                ->where('role_id', $roleId)
                ->where('permission_id', Permission::findByName($name, 'web')->id)
                ->count()
        )->toBe(1);
    }
});

it('is safe to run twice', function () {
    // Deploy paths can replay a one-shot; the second pass must be a no-op.
    grantFeeWriteToLoanOfficer();

    $migration = require database_path(FEE_REVOKE_MIGRATION);
    $migration->up();
    $migration->up();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $role = Role::findByName('loan_officer', 'web');
    expect($role->hasPermissionTo('fees:create'))->toBeFalse()
        ->and($role->hasPermissionTo('fees:view'))->toBeTrue();
});
