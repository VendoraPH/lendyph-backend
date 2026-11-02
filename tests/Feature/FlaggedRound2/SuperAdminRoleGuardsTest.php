<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Item 3: "Only a super_admin may change super_admin's display name." Clearing
 * it is a change too; sending the stored value unchanged is not.
 *
 * Item 4 (plan, decision 3): super_admin's permissions can't be changed by a
 * non-super_admin (sending the stored list unchanged is fine, so a role form
 * that always sends the list keeps working), and nobody but a super_admin may
 * grant a permission they do not hold themselves.
 */

function flaggedClientAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::where('name', 'admin')->firstOrFail());

    return $user;
}

it('refuses a client admin clearing the super_admin display_name, but accepts it unchanged', function (?string $cleared) {
    $superAdmin = Role::where('name', 'super_admin')->firstOrFail();
    $superAdmin->update(['display_name' => 'Platform Owner']);
    $admin = flaggedClientAdmin();

    $this->actingAs($admin)
        ->putJson("/api/roles/{$superAdmin->id}", ['display_name' => 'Platform Owner'])
        ->assertSuccessful();

    $this->actingAs($admin)
        ->putJson("/api/roles/{$superAdmin->id}", ['display_name' => $cleared])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['display_name']);

    expect($superAdmin->fresh()->display_name)->toBe('Platform Owner');
})->with([
    'null' => [null],
    'empty string' => [''],
    'spaces only' => ['   '],
]);

it('refuses a client admin changing super_admin\'s permissions, but accepts the list unchanged', function () {
    $superAdmin = Role::where('name', 'super_admin')->firstOrFail();
    $stored = $superAdmin->permissions->pluck('name')->sort()->values()->all();
    $admin = flaggedClientAdmin();

    $this->actingAs($admin)
        ->putJson("/api/roles/{$superAdmin->id}", ['permissions' => $stored])
        ->assertSuccessful();

    $this->actingAs($admin)
        ->putJson("/api/roles/{$superAdmin->id}", ['permissions' => ['dashboard:view']])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['permissions']);

    expect($superAdmin->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe($stored);
});

it('refuses a non-super_admin granting a permission they do not hold, on another role or their own', function () {
    $clerkRole = Role::create(['name' => 'settings_clerk', 'guard_name' => 'web', 'is_system' => false]);
    $clerkRole->syncPermissions(['settings:view', 'settings:update', 'dashboard:view']);
    $clerk = User::factory()->create();
    $clerk->assignRole($clerkRole);

    $target = Role::create(['name' => 'front_desk', 'guard_name' => 'web', 'is_system' => false]);
    $target->syncPermissions(['dashboard:view']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($clerk)
        ->putJson("/api/roles/{$target->id}", ['permissions' => ['dashboard:view', 'loans:approve']])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['permissions']);

    $this->actingAs($clerk)
        ->putJson("/api/roles/{$clerkRole->id}", ['permissions' => ['settings:view', 'settings:update', 'dashboard:view', 'loans:approve']])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['permissions']);

    $this->actingAs($clerk)
        ->postJson('/api/roles', ['name' => 'approver_clone', 'permissions' => ['loans:approve']])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['permissions']);

    expect($target->fresh()->permissions->pluck('name')->all())->toBe(['dashboard:view']);
    expect($clerkRole->fresh()->permissions->pluck('name')->contains('loans:approve'))->toBeFalse();
    expect(Role::where('name', 'approver_clone')->exists())->toBeFalse();
});

it('lets a super_admin change super_admin\'s permissions', function () {
    $superAdmin = Role::where('name', 'super_admin')->firstOrFail();
    $kept = $superAdmin->permissions->pluck('name')->reject(fn ($name) => $name === 'reports:export')->values()->all();

    $this->actingAs(User::where('username', 'super_admin')->firstOrFail())
        ->putJson("/api/roles/{$superAdmin->id}", ['permissions' => $kept])
        ->assertSuccessful();

    expect($superAdmin->fresh()->permissions->pluck('name')->contains('reports:export'))->toBeFalse();
});

it('lets a non-super_admin remove a permission they do not hold', function () {
    $clerkRole = Role::create(['name' => 'settings_clerk', 'guard_name' => 'web', 'is_system' => false]);
    $clerkRole->syncPermissions(['settings:view', 'settings:update', 'dashboard:view']);
    $clerk = User::factory()->create();
    $clerk->assignRole($clerkRole);

    $target = Role::create(['name' => 'approver_desk', 'guard_name' => 'web', 'is_system' => false]);
    $target->syncPermissions(['dashboard:view', 'loans:approve']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($clerk)
        ->putJson("/api/roles/{$target->id}", ['permissions' => ['dashboard:view']])
        ->assertSuccessful();

    expect($target->fresh()->permissions->pluck('name')->all())->toBe(['dashboard:view']);
});

it('lets a client admin edit the admin role\'s own permissions', function () {
    $adminRole = Role::where('name', 'admin')->firstOrFail();
    $kept = $adminRole->permissions->pluck('name')->reject(fn ($name) => $name === 'reports:export')->values()->all();

    $this->actingAs(flaggedClientAdmin())
        ->putJson("/api/roles/{$adminRole->id}", ['permissions' => $kept])
        ->assertSuccessful();

    expect($adminRole->fresh()->permissions->pluck('name')->contains('reports:export'))->toBeFalse();
});

function flaggedRoleAuditCount(): int
{
    return AuditLog::where('auditable_type', (new Role)->getMorphClass())->count();
}

it('refuses renaming the super_admin or admin key even when is_system is not set', function (string $key) {
    $role = Role::where('name', $key)->firstOrFail();
    DB::table('roles')->where('id', $role->id)->update(['is_system' => false]);

    $this->actingAs(flaggedClientAdmin())
        ->putJson("/api/roles/{$role->id}", ['name' => 'platform_owner'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);

    expect($role->fresh()->name)->toBe($key);
})->with(['super_admin', 'admin']);

it('refuses deleting the super_admin or admin role even when is_system is not set', function (string $key) {
    $role = Role::where('name', $key)->firstOrFail();
    DB::table('roles')->where('id', $role->id)->update(['is_system' => false]);

    $this->actingAs(flaggedClientAdmin())
        ->deleteJson("/api/roles/{$role->id}")
        ->assertStatus(422)
        ->assertJsonPath('errors.role.0', "System role '{$key}' cannot be deleted.");

    expect(Role::where('name', $key)->exists())->toBeTrue();
})->with(['super_admin', 'admin']);

it('accepts a client admin\'s description-only save of super_admin when its stored label is the default', function () {
    $superAdmin = Role::where('name', 'super_admin')->firstOrFail();
    DB::table('roles')->where('id', $superAdmin->id)->update(['display_name' => 'Super Admin']);
    $stored = $superAdmin->permissions->pluck('name')->all();
    $before = flaggedRoleAuditCount();

    // What the role editor sends: the default label as null, the full list.
    $this->actingAs(flaggedClientAdmin())
        ->putJson("/api/roles/{$superAdmin->id}", [
            'display_name' => null,
            'description' => 'Platform team',
            'permissions' => $stored,
        ])
        ->assertSuccessful();

    $fresh = $superAdmin->fresh();
    expect($fresh->display_name)->toBe('Super Admin');
    expect($fresh->description)->toBe('Platform team');

    $rows = AuditLog::where('auditable_type', (new Role)->getMorphClass())->where('auditable_id', $superAdmin->id)->orderBy('id')->get();
    expect(flaggedRoleAuditCount())->toBe($before + 1);
    expect($rows->last()->action)->toBe('updated');
    expect($rows->last()->new_values)->toHaveKey('description');
    expect($rows->last()->new_values)->not->toHaveKey('display_name');
});

it('writes no audit row for a refused save', function () {
    $superAdmin = Role::where('name', 'super_admin')->firstOrFail();
    $target = Role::create(['name' => 'front_desk', 'guard_name' => 'web', 'is_system' => false]);
    $target->syncPermissions(['dashboard:view']);
    $clerkRole = Role::create(['name' => 'settings_clerk', 'guard_name' => 'web', 'is_system' => false]);
    $clerkRole->syncPermissions(['settings:view', 'settings:update', 'dashboard:view']);
    $clerk = User::factory()->create();
    $clerk->assignRole($clerkRole);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $before = flaggedRoleAuditCount();

    $this->actingAs(flaggedClientAdmin())
        ->putJson("/api/roles/{$superAdmin->id}", ['description' => 'Changed', 'permissions' => ['dashboard:view']])
        ->assertStatus(422);

    $this->actingAs($clerk)
        ->putJson("/api/roles/{$target->id}", ['description' => 'Changed', 'permissions' => ['dashboard:view', 'loans:approve']])
        ->assertStatus(422);

    expect(flaggedRoleAuditCount())->toBe($before);
    expect($superAdmin->fresh()->description)->not->toBe('Changed');
    expect($target->fresh()->description)->not->toBe('Changed');
});

it('accepts super_admin\'s unchanged list in another order and with duplicates', function () {
    $superAdmin = Role::where('name', 'super_admin')->firstOrFail();
    $stored = $superAdmin->permissions->pluck('name')->sort()->values()->all();
    $reordered = [$stored[0], ...array_reverse($stored)];
    $before = flaggedRoleAuditCount();

    $this->actingAs(flaggedClientAdmin())
        ->putJson("/api/roles/{$superAdmin->id}", ['permissions' => $reordered])
        ->assertSuccessful();

    expect($superAdmin->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe($stored);
    expect(flaggedRoleAuditCount())->toBe($before);
});

it('refuses a client admin sending super_admin an empty or null permission list, which would revoke them all', function (?array $permissions) {
    $superAdmin = Role::where('name', 'super_admin')->firstOrFail();
    $stored = $superAdmin->permissions->pluck('name')->sort()->values()->all();

    $this->actingAs(flaggedClientAdmin())
        ->putJson("/api/roles/{$superAdmin->id}", ['permissions' => $permissions])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['permissions']);

    expect($superAdmin->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe($stored);
})->with([
    'null' => [null],
    'empty list' => [[]],
]);

it('judges a grant again against the role\'s permissions under the lock', function () {
    $clerkRole = Role::create(['name' => 'settings_clerk', 'guard_name' => 'web', 'is_system' => false]);
    $clerkRole->syncPermissions(['settings:view', 'settings:update', 'dashboard:view']);
    $clerk = User::factory()->create();
    $clerk->assignRole($clerkRole);

    // At validation the role already carries loans:approve, so sending it
    // back is no grant.
    $target = Role::create(['name' => 'approver_desk', 'guard_name' => 'web', 'is_system' => false]);
    $target->syncPermissions(['dashboard:view', 'loans:approve']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Another save takes it off between validation and this write.
    Role::updated(function (Role $role) use ($target) {
        if ($role->id === $target->id) {
            DB::table('role_has_permissions')
                ->where('role_id', $target->id)
                ->where('permission_id', DB::table('permissions')->where('name', 'loans:approve')->value('id'))
                ->delete();
        }
    });

    $before = flaggedRoleAuditCount();

    $this->actingAs($clerk)
        ->putJson("/api/roles/{$target->id}", ['description' => 'Desk', 'permissions' => ['dashboard:view', 'loans:approve']])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['permissions']);

    expect($target->fresh()->description)->not->toBe('Desk');
    expect(flaggedRoleAuditCount())->toBe($before);
});

it('refuses a protected key as a new role name even when that role\'s row is missing', function (string $key) {
    DB::table('roles')->where('name', $key)->delete();
    $custom = Role::create(['name' => 'desk_lead', 'guard_name' => 'web', 'is_system' => false]);

    // Refused for every actor, super_admin included where the row is admin's.
    $actor = $key === 'super_admin' ? flaggedClientAdmin() : User::where('username', 'super_admin')->firstOrFail();

    $this->actingAs($actor)
        ->postJson('/api/roles', ['name' => $key])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);

    $this->actingAs($actor)
        ->putJson("/api/roles/{$custom->id}", ['name' => $key])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);

    expect(Role::where('name', $key)->exists())->toBeFalse();
    expect($custom->fresh()->name)->toBe('desk_lead');
})->with(['super_admin', 'admin']);

it('locks every role row before counting users or touching links when a role is deleted', function () {
    $role = Role::create(['name' => 'temp_desk', 'guard_name' => 'web', 'is_system' => false]);
    $role->syncPermissions(['dashboard:view']);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = strtolower($query->sql);
    });

    $this->actingAs(User::where('username', 'super_admin')->firstOrFail())
        ->deleteJson("/api/roles/{$role->id}")
        ->assertSuccessful();

    $first = fn (callable $matches) => collect($queries)->search(fn (string $sql) => $matches($sql));
    $lock = $first(fn (string $sql) => str_contains($sql, 'from `roles`') && str_contains($sql, 'for update'));
    $userCount = $first(fn (string $sql) => str_contains($sql, 'model_has_roles') && str_contains($sql, 'count('));
    $firstWrite = $first(fn (string $sql) => str_starts_with($sql, 'delete') || str_starts_with($sql, 'insert'));

    expect($lock)->toBeInt();
    expect($lock)->toBeLessThan($userCount);
    expect($lock)->toBeLessThan($firstWrite);
});
