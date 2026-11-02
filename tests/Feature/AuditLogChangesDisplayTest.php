<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use Tests\TestCase;

uses(TestCase::class);

/*
 * `changes` on an audit row lists only fields that appear in the new values
 * and differ from the old ones. An `updated` row's old values hold the whole
 * original row, so untouched fields used to show as "→ —". A `created` row
 * shows no field list (the frontend then shows just "Created"). Display only:
 * the stored old_values and new_values are returned unchanged.
 */

beforeEach(function () {
    $this->actingAs(User::where('username', 'super_admin')->firstOrFail());
});

function auditRowFor(Role $role, string $action): AuditLog
{
    return AuditLog::query()
        ->where('action', $action)
        ->where('auditable_type', $role->getMorphClass())
        ->where('auditable_id', $role->id)
        ->latest('id')
        ->firstOrFail();
}

it('lists only display_name when a role is relabelled', function () {
    $role = Role::create(['name' => 'relabel_probe', 'guard_name' => 'web', 'is_system' => false, 'description' => 'Front desk']);

    $this->putJson("/api/roles/{$role->id}", ['display_name' => 'Teller'])->assertSuccessful();
    $row = auditRowFor($role, 'updated');

    $response = $this->getJson("/api/audit-logs/{$row->id}")->assertOk();

    expect($response->json('data.changes'))->toBe([
        ['field' => 'display_name', 'old' => null, 'new' => 'Teller'],
    ]);

    // The stored row is untouched and still returned as it is.
    expect($response->json('data.old_values.name'))->toBe('relabel_probe')
        ->and($response->json('data.old_values.description'))->toBe('Front desk');
});

it('shows a created row with no field list, so no id or timestamps', function () {
    $this->postJson('/api/roles', ['name' => 'created_probe', 'display_name' => 'Probe'])->assertCreated();
    $row = auditRowFor(Role::where('name', 'created_probe')->firstOrFail(), 'created');

    expect($row->new_values)->toHaveKeys(['id', 'created_at', 'updated_at']);

    $this->getJson("/api/audit-logs/{$row->id}")
        ->assertOk()
        ->assertJsonPath('data.changes', [])
        ->assertJsonPath('data.new_values.name', 'created_probe');
});

it('never lists a field whose value did not change', function () {
    $row = AuditLogService::log(
        'probe_changed',
        null,
        ['amount' => '1500.00', 'status' => 'pending', 'note' => 'kept'],
        ['amount' => '1500.00', 'status' => 'paid', 'extra' => 'new'],
    );

    $changes = collect($this->getJson("/api/audit-logs/{$row->id}")->assertOk()->json('data.changes'));

    // A MySQL JSON column stores keys in its own order, so order is not pinned.
    expect($changes->pluck('field')->sort()->values()->all())->toBe(['extra', 'status'])
        ->and($changes->firstWhere('field', 'status'))->toBe(['field' => 'status', 'old' => 'pending', 'new' => 'paid'])
        ->and($changes->firstWhere('field', 'extra'))->toBe(['field' => 'extra', 'old' => null, 'new' => 'new']);
});

it('lists the list fields of a permission change row', function () {
    $role = Role::create(['name' => 'perm_probe', 'guard_name' => 'web', 'is_system' => false]);
    $this->putJson("/api/roles/{$role->id}", ['permissions' => ['loans:view']])->assertSuccessful();
    $row = auditRowFor($role, 'role_permissions_changed');

    $this->getJson("/api/audit-logs/{$row->id}")
        ->assertOk()
        ->assertJsonPath('data.changes', [['field' => 'permissions', 'old' => '[]', 'new' => '["loans:view"]']]);
});

it('lists the old fields of a deleted row with new: null, without timestamps', function () {
    $role = Role::create(['name' => 'deleted_probe', 'guard_name' => 'web', 'is_system' => false, 'description' => 'Gone soon']);

    $this->deleteJson("/api/roles/{$role->id}")->assertSuccessful();
    $row = auditRowFor($role, 'deleted');

    expect($row->new_values)->toBeNull();

    $changes = collect($this->getJson("/api/audit-logs/{$row->id}")->assertOk()->json('data.changes'))->keyBy('field');

    expect($changes->get('name'))->toBe(['field' => 'name', 'old' => 'deleted_probe', 'new' => null])
        ->and($changes->get('description'))->toBe(['field' => 'description', 'old' => 'Gone soon', 'new' => null])
        ->and($changes->keys()->intersect(['created_at', 'updated_at', 'deleted_at'])->all())->toBe([])
        ->and($changes->pluck('new')->filter()->all())->toBe([]);
});
