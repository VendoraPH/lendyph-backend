<?php

use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use Tests\TestCase;

uses(TestCase::class);

/*
 * `module` filters the audit log list (and its CSV export) by the same module
 * names AuditLogResource sends, mapped to their auditable classes. The old
 * `auditable_type` filter keeps working as before. An unknown module is a 422.
 */

beforeEach(function () {
    $this->actingAs(User::where('username', 'super_admin')->firstOrFail());
    $this->role = Role::create(['name' => 'module_probe', 'guard_name' => 'web', 'is_system' => false]);
    $this->user = User::factory()->create();
    $this->login = AuditLogService::log('login', null, null, null, 'Signed in');
});

function auditListModules(array $rows): array
{
    return collect($rows)->pluck('module')->unique()->values()->all();
}

it('returns only role rows for module=roles', function () {
    $rows = $this->getJson('/api/audit-logs?module=roles&per_page=100')->assertOk()->json('data');

    expect($rows)->not->toBeEmpty()
        ->and(auditListModules($rows))->toBe(['roles'])
        ->and(collect($rows)->pluck('auditable_type')->unique()->all())->toBe(['Role'])
        ->and(collect($rows)->pluck('auditable_id'))->toContain($this->role->id);
});

it('returns only user rows for module=users', function () {
    $rows = $this->getJson('/api/audit-logs?module=users&per_page=100')->assertOk()->json('data');

    expect($rows)->not->toBeEmpty()
        ->and(auditListModules($rows))->toBe(['users'])
        ->and(collect($rows)->pluck('auditable_type')->unique()->all())->toBe(['User'])
        ->and(collect($rows)->pluck('auditable_id'))->toContain($this->user->id);
});

it('returns auth events for module=auth', function () {
    $rows = $this->getJson('/api/audit-logs?module=auth&per_page=100')->assertOk()->json('data');

    expect(collect($rows)->pluck('id'))->toContain($this->login->id)
        ->and(auditListModules($rows))->toBe(['auth']);
});

it('refuses an unknown module with 422', function () {
    $this->getJson('/api/audit-logs?module=reports')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['module']);

    $this->getJson('/api/audit-logs/export?module=nope')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['module']);
});

it('keeps the auditable_type filter working as before', function () {
    $rows = $this->getJson('/api/audit-logs?auditable_type=Role&per_page=100')->assertOk()->json('data');

    expect($rows)->not->toBeEmpty()
        ->and(collect($rows)->pluck('auditable_type')->unique()->all())->toBe(['Role']);
});

it('filters the CSV export by module too', function () {
    $csv = $this->get('/api/audit-logs/export?module=roles', ['Accept' => 'application/json'])
        ->assertOk()
        ->streamedContent();

    $lines = array_values(array_filter(explode("\n", trim($csv))));

    expect(count($lines))->toBeGreaterThan(1);
    foreach (array_slice($lines, 1) as $line) {
        expect(str_getcsv($line)[3])->toBe('Role');
    }
});
