<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

uses(TestCase::class);

/*
 * A role's `updated` audit row says what changed (`Role "Teller" (cashier)
 * updated: display_name, description`), and the Audit Trail files every role row
 * (created, updated, deleted, role_permissions_changed) under the `roles`
 * module, not `users`.
 */

beforeEach(function () {
    $this->actingAs(User::where('username', 'super_admin')->firstOrFail());
});

function roleAuditRow(Role $role, string $action): AuditLog
{
    return AuditLog::query()
        ->where('action', $action)
        ->where('auditable_type', $role->getMorphClass())
        ->where('auditable_id', $role->id)
        ->latest('id')
        ->firstOrFail();
}

it('describes an updated role row by its display name and the changed fields', function () {
    $cashier = Role::where('name', 'cashier')->firstOrFail();

    $this->putJson("/api/roles/{$cashier->id}", ['display_name' => 'Teller', 'description' => 'Counter staff'])
        ->assertSuccessful();

    expect(roleAuditRow($cashier, 'updated')->description)
        ->toBe('Role "Teller" (cashier) updated: display_name, description');
});

it('falls back to the default label when the role has no display name', function () {
    $role = Role::create(['name' => 'branch_clerk', 'guard_name' => 'web', 'is_system' => false]);

    $this->putJson("/api/roles/{$role->id}", ['description' => 'Files paperwork'])->assertSuccessful();

    expect(roleAuditRow($role, 'updated')->description)->toBe('Role "Branch Clerk" (branch_clerk) updated: description');
});

it('describes a deactivation as an update of is_active', function () {
    $role = Role::create(['name' => 'seasonal_staff', 'guard_name' => 'web', 'is_system' => false, 'display_name' => 'Seasonal']);

    $this->patchJson("/api/roles/{$role->id}/deactivate")->assertSuccessful();

    expect(roleAuditRow($role, 'updated')->description)->toBe('Role "Seasonal" (seasonal_staff) updated: is_active');
});

it('files role rows under the roles module in the Audit Trail', function () {
    $this->postJson('/api/roles', [
        'name' => 'loan_reviewer',
        'display_name' => 'Loan Reviewer',
        'permissions' => ['loans:view'],
    ])->assertCreated();

    $role = Role::where('name', 'loan_reviewer')->firstOrFail();
    $this->putJson("/api/roles/{$role->id}", ['display_name' => 'Senior Reviewer'])->assertSuccessful();

    foreach (['created', 'updated', 'role_permissions_changed'] as $action) {
        $row = roleAuditRow($role, $action);

        $this->getJson("/api/audit-logs/{$row->id}")
            ->assertOk()
            ->assertJsonPath('data.module', 'roles')
            ->assertJsonPath('data.target.type', 'Role');
    }
});

it('keeps user rows under the users module', function () {
    $user = User::factory()->create();
    $row = AuditLog::query()
        ->where('auditable_type', $user->getMorphClass())
        ->where('auditable_id', $user->id)
        ->firstOrFail();

    $this->getJson("/api/audit-logs/{$row->id}")
        ->assertOk()
        ->assertJsonPath('data.module', 'users');
});
