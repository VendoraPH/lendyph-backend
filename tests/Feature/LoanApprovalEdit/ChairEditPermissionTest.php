<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionWords;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * `loans:edit_in_approval` (finish run round 2, decision 14): exclusive to the
 * Chairman/Chairwoman's role, `bod1`. Not admin, not super_admin, no other
 * role: from the seeder on a fresh database and from
 * 2026_11_24_300000_add_loans_edit_in_approval_permission on an
 * already-migrated one, with one system audit row and nothing on a re-run.
 */
uses(TestCase::class);

const CHAIR_EDIT_PERMISSION_MIGRATION = '2026_11_24_300000_add_loans_edit_in_approval_permission';

function chairEditPermissionMigration(): object
{
    return require database_path('migrations/'.CHAIR_EDIT_PERMISSION_MIGRATION.'.php');
}

/**
 * Every role holding the permission, by key.
 *
 * @return list<string>
 */
function chairEditHolders(): array
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return Role::query()
        ->whereHas('permissions', fn ($permissions) => $permissions->where('name', 'loans:edit_in_approval'))
        ->pluck('name')
        ->sort()
        ->values()
        ->all();
}

beforeEach(function () {
    $this->actingAs(User::where('username', 'super_admin')->first());
});

it('exists once, on the web guard', function () {
    expect(Permission::where('name', 'loans:edit_in_approval')->pluck('guard_name')->all())->toBe(['web']);
});

it('is held by the Chair\'s role alone: not admin, not super_admin, no other role', function () {
    expect(chairEditHolders())->toBe(['bod1']);
});

it('seeds it for the Chair alone on a fresh database, where the migration finds no roles to grant to', function () {
    chairEditPermissionMigration()->down();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(Permission::where('name', 'loans:edit_in_approval')->exists())->toBeFalse();

    $this->seed(RoleAndPermissionSeeder::class);

    expect(chairEditHolders())->toBe(['bod1'])
        ->and(Role::where('name', 'admin')->firstOrFail()->permissions()->count())->toBe(Permission::count() - 1)
        ->and(Role::where('name', 'super_admin')->firstOrFail()->permissions()->count())->toBe(Permission::count() - 1);
});

it('grants it to the Chair alone, with one system audit row, when the roles already exist', function () {
    chairEditPermissionMigration()->down();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Auth::logout();
    $before = AuditLog::query()->max('id') ?? 0;

    chairEditPermissionMigration()->up();

    $rows = AuditLog::query()->where('id', '>', $before)->get();

    expect(chairEditHolders())->toBe(['bod1'])
        ->and($rows)->toHaveCount(1)
        ->and($rows->first()->action)->toBe('role_permissions_changed')
        ->and($rows->first()->user_id)->toBeNull()
        ->and((string) $rows->first()->description)->toContain(CHAIR_EDIT_PERMISSION_MIGRATION);
});

it('is safe to run again, writing no audit row', function () {
    chairEditPermissionMigration()->down();
    chairEditPermissionMigration()->up();

    $counts = [DB::table('permissions')->count(), DB::table('role_has_permissions')->count(), AuditLog::query()->count()];

    chairEditPermissionMigration()->up();

    expect([DB::table('permissions')->count(), DB::table('role_has_permissions')->count(), AuditLog::query()->count()])->toBe($counts)
        ->and(chairEditHolders())->toBe(['bod1']);
});

it('grants it to nobody on a deployment with no Chair role', function () {
    chairEditPermissionMigration()->down();
    Role::where('name', 'bod1')->firstOrFail()->delete();

    chairEditPermissionMigration()->up();

    expect(Permission::where('name', 'loans:edit_in_approval')->exists())->toBeTrue()
        ->and(chairEditHolders())->toBe([]);
});

it('keeps a grant a super admin made to a custom chair role in the roles screen', function () {
    $custom = Role::create(['name' => 'bod_chairwoman', 'guard_name' => 'web', 'is_active' => true]);
    $custom->givePermissionTo('loans:edit_in_approval');

    chairEditPermissionMigration()->up();

    expect(chairEditHolders())->toBe(['bod1', 'bod_chairwoman']);
});

it('can\'t be handed to another role by an admin, who doesn\'t hold it', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $manager = Role::where('name', 'manager')->firstOrFail();
    $permissions = $manager->permissions()->pluck('name')->push('loans:edit_in_approval')->all();

    $this->actingAs($admin)
        ->putJson("/api/roles/{$manager->id}", ['permissions' => $permissions])
        ->assertUnprocessable()
        ->assertJsonPath('errors.permissions.0', 'Only a super admin can grant the permission to edit loans during approval.');

    expect(chairEditHolders())->toBe(['bod1']);
});

it('tells an admin who switched the Chair\'s role off, in words, that only a super admin can switch it back on', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $chair = Role::where('name', 'bod1')->firstOrFail();

    $this->actingAs($admin)->patchJson("/api/roles/{$chair->id}/deactivate")->assertOk();
    $this->actingAs($admin)
        ->patchJson("/api/roles/{$chair->id}/reactivate")
        ->assertUnprocessable()
        ->assertJsonPath('errors.role.0', 'Only a super admin can grant the permission to edit loans during approval.');

    expect($chair->fresh()->is_active)->toBeFalse();
});

/**
 * A user in Main Branch with the role named, as the user-management tests
 * make them.
 */
function chairEditStaff(string $role): User
{
    return tap(User::factory()->create(['branch_id' => Branch::query()->firstOrFail()->id]), fn (User $user) => $user->assignRole($role));
}

it('tells an admin in words that only a super admin can assign the Chair\'s role', function () {
    $target = chairEditStaff('viewer');

    $this->actingAs(chairEditStaff('admin'))
        ->putJson("/api/users/{$target->id}", ['role' => 'bod1'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.role.0', 'Only a super admin can assign a role that can edit loans during approval.');

    expect($target->fresh()->getRoleNames()->all())->toBe(['viewer']);
});

it('lets a super admin assign the Chair\'s role', function () {
    $target = chairEditStaff('viewer');

    $this->putJson("/api/users/{$target->id}", ['role' => 'bod1'])->assertOk();

    expect($target->fresh()->getRoleNames()->all())->toBe(['bod1']);
});

it('names the permissions a role would grant in words, never as keys', function () {
    Role::create(['name' => 'user_desk', 'guard_name' => 'web', 'is_system' => false])
        ->syncPermissions(['users:view', 'users:update', 'loans:view']);
    Role::create(['name' => 'starting_desk', 'guard_name' => 'web', 'is_system' => false])
        ->syncPermissions(['loans:view']);
    $target = chairEditStaff('starting_desk');

    $message = $this->actingAs(chairEditStaff('user_desk'))
        ->putJson("/api/users/{$target->id}", ['role' => 'loan_officer'])
        ->assertUnprocessable()
        ->json('errors.role.0');

    expect($message)->toStartWith('The selected role grants permissions you do not hold yourself: ')
        ->and($message)->not->toMatch('/[a-z_]+:[a-z_]+/');
});

it('names the permission as the role editor does, wherever the server names it', function () {
    expect(PermissionWords::of('loans:edit_in_approval'))->toBe('Loans: Edit loans at their approval step')
        ->and(PermissionWords::of('borrowers:view'))->toBe('Members: View');
});
