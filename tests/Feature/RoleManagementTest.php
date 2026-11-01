<?php

use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->admin = User::where('username', 'super_admin')->first();
    $this->actingAs($this->admin);
});

it('lists all roles with is_system, is_active, users_count', function () {
    $response = $this->getJson('/api/roles')->assertSuccessful();

    $admin = collect($response->json('data'))->firstWhere('name', 'admin');
    expect($admin)->not->toBeNull();
    expect($admin['is_system'])->toBeTrue();
    expect($admin['is_active'])->toBeTrue();
    expect($admin['users_count'])->toBeInt();
    expect($admin)->toHaveKey('description');
});

it('creates a custom role with permissions', function () {
    $response = $this->postJson('/api/roles', [
        'name' => 'branch_manager',
        'description' => 'Manages a branch',
        'permissions' => ['dashboard:view', 'borrowers:view', 'loans:view'],
    ])->assertCreated();

    expect($response->json('data.name'))->toBe('branch_manager');
    expect($response->json('data.is_system'))->toBeFalse();
    expect($response->json('data.is_active'))->toBeTrue();
    expect($response->json('data.permissions'))->toContain('dashboard:view');

    $this->assertDatabaseHas('roles', [
        'name' => 'branch_manager',
        'is_system' => false,
        'is_active' => true,
    ]);
});

it('rejects custom role name that is not snake_case', function () {
    $this->postJson('/api/roles', [
        'name' => 'BranchManager',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

it('rejects duplicate custom role name', function () {
    Role::create(['name' => 'branch_manager', 'guard_name' => 'web']);

    $this->postJson('/api/roles', [
        'name' => 'branch_manager',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

it('updates description and permissions on a custom role', function () {
    $role = Role::create(['name' => 'cash_manager', 'guard_name' => 'web', 'is_system' => false]);
    $role->syncPermissions(['dashboard:view']);

    $this->putJson("/api/roles/{$role->id}", [
        'description' => 'Handles daily cashflow',
        'permissions' => ['dashboard:view', 'payments:view', 'payments:create'],
    ])->assertSuccessful()
        ->assertJsonPath('data.description', 'Handles daily cashflow');

    expect($role->fresh()->permissions->pluck('name')->sort()->values()->toArray())
        ->toBe(['dashboard:view', 'payments:create', 'payments:view']);
});

it('rejects rename of a system role', function () {
    $admin = Role::where('name', 'admin')->first();

    $this->putJson("/api/roles/{$admin->id}", [
        'name' => 'super_admin',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

it('allows description edit on a system role', function () {
    $loanOfficer = Role::where('name', 'loan_officer')->first();

    $this->putJson("/api/roles/{$loanOfficer->id}", [
        'description' => 'Updated description only',
    ])->assertSuccessful()
        ->assertJsonPath('data.description', 'Updated description only');
});

it('deactivates and reactivates a custom role', function () {
    $role = Role::create(['name' => 'temp_role', 'guard_name' => 'web', 'is_system' => false]);

    $this->patchJson("/api/roles/{$role->id}/deactivate")
        ->assertSuccessful()
        ->assertJsonPath('data.is_active', false);

    $this->patchJson("/api/roles/{$role->id}/reactivate")
        ->assertSuccessful()
        ->assertJsonPath('data.is_active', true);
});

it('rejects deactivation of the admin role', function () {
    $admin = Role::where('name', 'admin')->first();

    $this->patchJson("/api/roles/{$admin->id}/deactivate")
        ->assertStatus(422);
});

it('deletes a custom role with no assigned users', function () {
    $role = Role::create(['name' => 'deletable', 'guard_name' => 'web', 'is_system' => false]);

    $this->deleteJson("/api/roles/{$role->id}")
        ->assertSuccessful();

    $this->assertDatabaseMissing('roles', ['id' => $role->id]);
});

it('rejects deletion of a system role', function () {
    $admin = Role::where('name', 'admin')->first();

    $this->deleteJson("/api/roles/{$admin->id}")
        ->assertStatus(422);
});

it('rejects deletion of a custom role that has assigned users', function () {
    $role = Role::create(['name' => 'in_use', 'guard_name' => 'web', 'is_system' => false]);
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->deleteJson("/api/roles/{$role->id}")
        ->assertStatus(422);

    $this->assertDatabaseHas('roles', ['id' => $role->id]);
});

it('requires settings:delete permission to destroy a role (settings:update alone is insufficient)', function () {
    $custom = Role::create(['name' => 'deletable_by_delegated', 'guard_name' => 'web', 'is_system' => false]);

    $operator = User::factory()->create();
    $operatorRole = Role::create(['name' => 'settings_updater', 'guard_name' => 'web', 'is_system' => false]);
    $operatorRole->syncPermissions(['settings:view', 'settings:update', 'users:view']);
    $operator->assignRole($operatorRole);

    $this->actingAs($operator)
        ->deleteJson("/api/roles/{$custom->id}")
        ->assertStatus(403);

    $this->assertDatabaseHas('roles', ['id' => $custom->id]);
});

it('allows destroy when the user has settings:delete', function () {
    $custom = Role::create(['name' => 'deletable_role', 'guard_name' => 'web', 'is_system' => false]);

    $operator = User::factory()->create();
    $operatorRole = Role::create(['name' => 'settings_deleter', 'guard_name' => 'web', 'is_system' => false]);
    $operatorRole->syncPermissions(['settings:view', 'settings:delete', 'users:view']);
    $operator->assignRole($operatorRole);

    $this->actingAs($operator)
        ->deleteJson("/api/roles/{$custom->id}")
        ->assertSuccessful();

    $this->assertDatabaseMissing('roles', ['id' => $custom->id]);
});

it('exposes users_count on GET', function () {
    $role = Role::create(['name' => 'counted', 'guard_name' => 'web', 'is_system' => false]);
    User::factory()->count(2)->create()->each(fn ($u) => $u->assignRole($role));

    $response = $this->getJson("/api/roles/{$role->id}")->assertSuccessful();

    expect($response->json('data.users_count'))->toBe(2);
});

it('saves a display_name on a custom role without changing its key', function () {
    $role = Role::create(['name' => 'cash_manager', 'guard_name' => 'web', 'is_system' => false]);

    $this->putJson("/api/roles/{$role->id}", [
        'display_name' => 'Cash Desk Manager',
    ])->assertSuccessful()
        ->assertJsonPath('data.display_name', 'Cash Desk Manager')
        ->assertJsonPath('data.name', 'cash_manager');

    $this->assertDatabaseHas('roles', [
        'id' => $role->id,
        'name' => 'cash_manager',
        'display_name' => 'Cash Desk Manager',
    ]);
});

it('saves a display_name on a system role and still refuses a changed name', function () {
    $loanOfficer = Role::where('name', 'loan_officer')->first();

    $this->putJson("/api/roles/{$loanOfficer->id}", [
        'display_name' => 'Account Officer',
    ])->assertSuccessful()
        ->assertJsonPath('data.display_name', 'Account Officer')
        ->assertJsonPath('data.name', 'loan_officer');

    $this->putJson("/api/roles/{$loanOfficer->id}", [
        'name' => 'account_officer',
        'display_name' => 'Renamed Again',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['name']);

    expect($loanOfficer->fresh()->name)->toBe('loan_officer');
    expect($loanOfficer->fresh()->display_name)->toBe('Account Officer');
});

it('returns display_name on role reads', function () {
    $role = Role::create(['name' => 'counted_display', 'guard_name' => 'web', 'is_system' => false]);
    $role->forceFill(['display_name' => 'Counted Display'])->save();

    $this->getJson("/api/roles/{$role->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.display_name', 'Counted Display');

    $listed = collect($this->getJson('/api/roles')->assertSuccessful()->json('data'))
        ->firstWhere('name', 'counted_display');

    expect($listed)->toHaveKey('display_name');
    expect($listed['display_name'])->toBe('Counted Display');
});

it('accepts display_name when creating a role', function () {
    $this->postJson('/api/roles', [
        'name' => 'branch_teller',
        'display_name' => 'Branch Teller',
    ])->assertCreated()
        ->assertJsonPath('data.name', 'branch_teller')
        ->assertJsonPath('data.display_name', 'Branch Teller');

    $this->assertDatabaseHas('roles', [
        'name' => 'branch_teller',
        'display_name' => 'Branch Teller',
    ]);
});

it('rejects a display_name longer than 100 characters', function () {
    $role = Role::create(['name' => 'long_label', 'guard_name' => 'web', 'is_system' => false]);

    $this->putJson("/api/roles/{$role->id}", [
        'display_name' => str_repeat('a', 101),
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['display_name']);

    $this->postJson('/api/roles', [
        'name' => 'long_label_two',
        'display_name' => str_repeat('a', 101),
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['display_name']);
});
