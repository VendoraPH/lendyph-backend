<?php

use App\Models\Role;
use App\Models\User;
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
