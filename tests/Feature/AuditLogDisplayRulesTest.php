<?php

use App\Models\AuditLog;
use App\Models\BrandingSetting;
use App\Models\CsvImportRun;
use App\Models\LoanApprovalStep;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

uses(TestCase::class, SetupLendyPH::class);

/*
 * How the Audit Trail shows a row (display only; stored rows never change):
 * - timestamps never appear in the change list;
 * - login, logout and the other self-service auth events are filed under
 *   `auth` whatever their auditable type, and show the signed-in user as the
 *   actor when the row has no user_id;
 * - loan-workflow classes (approval steps, collaterals) are `loans`, and any
 *   other unmapped class is `system`; the `module` filter follows the same rules;
 * - role rows are labelled by the role's display name;
 * - user foreign keys in the change list show the user's name.
 */

beforeEach(function () {
    $this->seedAndLogin();
});

function latestAuditRow(string $action, ?string $type = null, ?int $id = null): AuditLog
{
    return AuditLog::query()
        ->where('action', $action)
        ->when($type, fn ($q) => $q->where('auditable_type', $type))
        ->when($id, fn ($q) => $q->where('auditable_id', $id))
        ->latest('id')
        ->firstOrFail();
}

it('never lists timestamps in the change list, even when the update lands in a later second', function () {
    $role = Role::create(['name' => 'stamp_probe', 'guard_name' => 'web', 'is_system' => false]);

    $this->travel(5)->seconds();
    $this->putJson("/api/roles/{$role->id}", ['display_name' => 'Stamp'])->assertSuccessful();

    $row = latestAuditRow('updated', $role->getMorphClass(), $role->id);
    expect($row->new_values)->toHaveKey('updated_at');

    $this->getJson("/api/audit-logs/{$row->id}")
        ->assertOk()
        ->assertJsonPath('data.changes', [['field' => 'display_name', 'old' => null, 'new' => 'Stamp']]);
});

it('files a login row under auth, not users, and records the signed-in user as its actor', function () {
    $this->app['auth']->forgetGuards();
    $this->postJson('/api/auth/login', ['login' => 'super_admin', 'password' => 'password'])->assertOk();

    $row = latestAuditRow('login', $this->admin->getMorphClass(), $this->admin->id);
    expect($row->user_id)->toBe($this->admin->id);

    $this->actingAs($this->admin);

    $this->getJson("/api/audit-logs/{$row->id}")
        ->assertOk()
        ->assertJsonPath('data.module', 'auth')
        ->assertJsonPath('data.user.id', $this->admin->id)
        ->assertJsonPath('data.user.full_name', $this->admin->full_name)
        ->assertJsonPath('data.actor_inferred', false);

    expect(collect($this->getJson('/api/audit-logs?module=auth&per_page=100')->json('data'))->pluck('id'))
        ->toContain($row->id);
    expect(collect($this->getJson('/api/audit-logs?module=users&per_page=100')->json('data'))->pluck('id'))
        ->not->toContain($row->id);
});

it('keeps the actor empty for an auth row with no user at all', function () {
    $row = AuditLog::create(['action' => 'logout', 'user_id' => null, 'description' => 'Token expired']);

    $this->getJson("/api/audit-logs/{$row->id}")
        ->assertOk()
        ->assertJsonPath('data.module', 'auth')
        ->assertJsonPath('data.user.id', null);
});

it('files approval-step rows under loans, and other unmapped classes under system', function () {
    $this->createReleasedLoan();
    $step = latestAuditRow('updated', (new LoanApprovalStep)->getMorphClass());

    $other = AuditLog::create([
        'action' => 'probe_unmapped',
        'auditable_type' => CsvImportRun::class,
        'auditable_id' => 999999,
        'description' => 'probe',
    ]);

    $this->getJson("/api/audit-logs/{$step->id}")->assertOk()->assertJsonPath('data.module', 'loans');
    $this->getJson("/api/audit-logs/{$other->id}")->assertOk()->assertJsonPath('data.module', 'system');

    $ids = fn (string $module) => collect($this->getJson("/api/audit-logs?module={$module}&per_page=100")->assertOk()->json('data'))->pluck('id');

    expect($ids('loans'))->toContain($step->id)
        ->and($ids('auth'))->not->toContain($step->id)->not->toContain($other->id)
        ->and($ids('system'))->toContain($other->id)->not->toContain($step->id);

    foreach (['loans', 'system', 'auth', 'users'] as $module) {
        $modules = collect($this->getJson("/api/audit-logs?module={$module}&per_page=100")->json('data'))->pluck('module')->unique()->values()->all();
        expect($modules)->toBeIn([[], [$module]]);
    }
});

it('labels a permission change row and its target by the role display name', function () {
    $labelled = Role::create(['name' => 'desk_viewer', 'guard_name' => 'web', 'is_system' => false, 'display_name' => 'Desk Viewer']);
    $plain = Role::create(['name' => 'night_clerk', 'guard_name' => 'web', 'is_system' => false]);

    $this->putJson("/api/roles/{$labelled->id}", ['permissions' => ['loans:view']])->assertSuccessful();
    $this->putJson("/api/roles/{$plain->id}", ['permissions' => ['loans:view']])->assertSuccessful();

    $row = latestAuditRow('role_permissions_changed', $labelled->getMorphClass(), $labelled->id);
    expect($row->description)->toBe('Permissions for role "Desk Viewer" (desk_viewer) changed: added [loans:view], removed []');
    expect(latestAuditRow('role_permissions_changed', $plain->getMorphClass(), $plain->id)->description)
        ->toBe('Permissions for role "Night Clerk" (night_clerk) changed: added [loans:view], removed []');

    $this->getJson("/api/audit-logs/{$row->id}")
        ->assertOk()
        ->assertJsonPath('data.target.label', 'Desk Viewer');
});

it('shows user names, not ids, for user foreign keys in the change list', function () {
    $this->createReleasedLoan();
    $row = AuditLog::query()
        ->where('action', 'updated')
        ->where('auditable_type', (new LoanApprovalStep)->getMorphClass())
        ->whereNotNull('new_values->acted_by')
        ->latest('id')
        ->firstOrFail();

    $actor = User::findOrFail($row->new_values['acted_by']);

    $change = collect($this->getJson("/api/audit-logs/{$row->id}")->assertOk()->json('data.changes'))
        ->firstWhere('field', 'acted_by');

    expect($change['new'])->toBe($actor->full_name);
});

it('loads the change-list user names in one query, whatever the page size', function () {
    $this->createReleasedLoan();
    $this->createReleasedLoan();
    $this->createReleasedLoan();

    $userQueries = function (int $perPage): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson("/api/audit-logs?module=loans&per_page={$perPage}")->assertOk();
        $count = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from `users`'))->count();
        DB::disableQueryLog();

        return $count;
    };

    expect($userQueries(40))->toBe($userQueries(2));
});

it('infers the actor of an old login row that has no user_id, and says so', function () {
    $row = AuditLog::create([
        'action' => 'login',
        'user_id' => null,
        'auditable_type' => $this->admin->getMorphClass(),
        'auditable_id' => $this->admin->id,
        'description' => 'User super_admin logged in',
    ]);

    $this->getJson("/api/audit-logs/{$row->id}")
        ->assertOk()
        ->assertJsonPath('data.user.id', $this->admin->id)
        ->assertJsonPath('data.actor_inferred', true);
});

it('does not infer an actor for a logout with no user_id', function () {
    $row = AuditLog::create([
        'action' => 'logout',
        'user_id' => null,
        'auditable_type' => $this->admin->getMorphClass(),
        'auditable_id' => $this->admin->id,
        'description' => 'User super_admin logged out',
    ]);

    $this->getJson("/api/audit-logs/{$row->id}")
        ->assertOk()
        ->assertJsonPath('data.user.id', null)
        ->assertJsonPath('data.actor_inferred', false);
});

it('shows the user in the CSV export for an old login row', function () {
    AuditLog::create([
        'action' => 'login',
        'user_id' => null,
        'auditable_type' => $this->admin->getMorphClass(),
        'auditable_id' => $this->admin->id,
        'description' => 'old-login-probe',
    ]);

    $csv = $this->get('/api/audit-logs/export?search=old-login-probe', ['Accept' => 'application/json'])
        ->assertOk()
        ->streamedContent();

    $rows = collect(explode("\n", trim($csv)))->slice(1)->map(fn (string $line) => str_getcsv($line, escape: ''))->values();

    expect($rows)->toHaveCount(1)
        ->and($rows[0][1])->toBe($this->admin->full_name);
});

it('files the GCash tiers-replaced row under gcash, for the label and the filter', function () {
    $this->putJson('/api/gcash/tiers', ['tiers' => [
        ['min_amount' => 1, 'max_amount' => 1000, 'cash_in_rate' => 20, 'cash_out_rate' => 20, 'display_order' => 1],
    ]])->assertOk();

    $row = AuditLog::query()->whereNull('auditable_type')->where('description', 'like', 'GCash tiers replaced%')->latest('id')->firstOrFail();

    $this->getJson("/api/audit-logs/{$row->id}")->assertOk()->assertJsonPath('data.module', 'gcash');

    $ids = fn (string $module) => collect($this->getJson("/api/audit-logs?module={$module}&per_page=100")->assertOk()->json('data'))->pluck('id');

    expect($ids('gcash'))->toContain($row->id)
        ->and($ids('system'))->not->toContain($row->id);
});

it('quotes the role label as a JSON string in descriptions', function () {
    $role = Role::create(['name' => 'quote_probe', 'guard_name' => 'web', 'is_system' => false]);
    $role->update(['display_name' => "Front \"Desk\" Jos\u{00E9}"]);

    expect(latestAuditRow('updated', $role->getMorphClass(), $role->id)->description)
        ->toBe("Role \"Front \\\"Desk\\\" Jos\u{00E9}\" (quote_probe) updated: display_name");
});

it('refuses, in tests, an audit row for a class with no module', function () {
    expect(fn () => AuditLogService::log('probe', new BrandingSetting))->toThrow(LogicException::class);
});
