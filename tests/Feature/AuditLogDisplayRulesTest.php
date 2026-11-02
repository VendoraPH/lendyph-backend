<?php

use App\Models\AuditLog;
use App\Models\CsvImportRun;
use App\Models\LoanApprovalStep;
use App\Models\Role;
use App\Models\User;
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

it('files a login row under auth, not users, and shows the signed-in user as the actor', function () {
    $this->app['auth']->forgetGuards();
    $this->postJson('/api/auth/login', ['login' => 'super_admin', 'password' => 'password'])->assertOk();

    $row = latestAuditRow('login', $this->admin->getMorphClass(), $this->admin->id);
    expect($row->user_id)->toBeNull();

    $this->actingAs($this->admin);

    $this->getJson("/api/audit-logs/{$row->id}")
        ->assertOk()
        ->assertJsonPath('data.module', 'auth')
        ->assertJsonPath('data.user.id', $this->admin->id)
        ->assertJsonPath('data.user.full_name', $this->admin->full_name);

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
    expect($row->description)->toBe('Permissions for role Desk Viewer changed: added [loans:view], removed []');
    expect(latestAuditRow('role_permissions_changed', $plain->getMorphClass(), $plain->id)->description)
        ->toBe('Permissions for role Night Clerk changed: added [loans:view], removed []');

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
