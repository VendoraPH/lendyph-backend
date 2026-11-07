<?php

use App\Http\Resources\AuditLogResource;
use App\Models\AccountingJournal;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\BrandingSetting;
use App\Models\CoMaker;
use App\Models\CsvImportRun;
use App\Models\GCashTransaction;
use App\Models\Loan;
use App\Models\LoanApprovalStep;
use App\Models\LoanProduct;
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

it('names a member target "Member" in the CSV export, the way the Audit Trail shows it', function () {
    $member = Borrower::factory()->create();
    foreach ([[$member->getMorphClass(), $member->id], [Loan::class, 1]] as [$type, $id]) {
        AuditLog::create([
            'action' => 'updated',
            'user_id' => $this->admin->id,
            'auditable_type' => $type,
            'auditable_id' => $id,
            'description' => 'target-type-probe',
        ]);
    }

    $csv = $this->get('/api/audit-logs/export?search=target-type-probe', ['Accept' => 'application/json'])
        ->assertOk()
        ->streamedContent();

    $rows = collect(explode("\n", trim($csv)))->slice(1)->map(fn (string $line) => str_getcsv($line, escape: ''))->values();

    expect($rows->pluck(3)->all())->toBe(['Member', 'Loan']);
});

it('reads array values in the change list in words, and returns the stored values as they are', function () {
    $old = [
        'tags' => ['urgent', 'manual'],
        'deductions' => [['name' => 'Processing Fee', 'amount' => 150], ['label' => 'Notarial Fee', 'amount' => 100]],
        'steps' => [['title' => 'Manager'], ['title' => 'BOD']],
        'lines' => [['amount' => 1], ['amount' => 2], ['amount' => 3]],
        'settings' => ['grace_days' => 3, 'rate' => 2],
        'collaterals' => [],
    ];
    $new = [
        'tags' => ['urgent'],
        'deductions' => [['name' => 'Processing Fee', 'amount' => 150]],
        'steps' => [],
        'lines' => [['amount' => 1]],
        'settings' => ['grace_days' => 5],
        'collaterals' => [7, 9],
    ];
    $row = AuditLog::create([
        'action' => 'updated',
        'user_id' => $this->admin->id,
        'old_values' => $old,
        'new_values' => $new,
        'description' => 'array-words-probe',
    ]);

    $response = $this->getJson("/api/audit-logs/{$row->id}")->assertOk();

    expect(collect($response->json('data.changes'))->keyBy('field')->map(fn ($c) => [$c['old'], $c['new']])->all())->toEqual([
        'tags' => ['urgent, manual', 'urgent'],
        'deductions' => ['Processing Fee, Notarial Fee', 'Processing Fee'],
        'steps' => ['Manager, BOD', 'none'],
        'lines' => ['3 entries', '1 entry'],
        'settings' => ['2 fields', '1 field'],
        'collaterals' => ['none', '7, 9'],
    ]);
    expect($response->json('data.old_values'))->toEqual($old)
        ->and($response->json('data.new_values'))->toEqual($new);
});

it('labels a journal target by its number once posted, else by its reference', function () {
    $journal = AccountingJournal::create([
        'date' => now()->toDateString(),
        'source' => 'loan_collection',
        'reference' => 'COL-10254',
        'description' => 'Collection',
    ]);
    $row = AuditLog::create([
        'action' => 'updated',
        'user_id' => $this->admin->id,
        'auditable_type' => $journal->getMorphClass(),
        'auditable_id' => $journal->id,
    ]);

    $this->getJson("/api/audit-logs/{$row->id}")->assertOk()->assertJsonPath('data.target.label', 'COL-10254');

    // Posting assigns the number; the model refuses updates, so set it as posting would.
    DB::table('accounting_journals')->where('id', $journal->id)->update(['journal_no' => 'JE-000557']);
    $this->getJson("/api/audit-logs/{$row->id}")->assertOk()->assertJsonPath('data.target.label', 'JE-000557');
});

it('names a target with no label by its class in words', function () {
    $journal = AccountingJournal::create([
        'date' => now()->toDateString(),
        'source' => 'loan_collection',
        'description' => 'No reference',
    ]);
    $label = function (string $type, int $id): string {
        $row = AuditLog::create([
            'action' => 'updated',
            'user_id' => $this->admin->id,
            'auditable_type' => $type,
            'auditable_id' => $id,
        ]);

        return $this->getJson("/api/audit-logs/{$row->id}")->assertOk()->json('data.target.label');
    };

    expect($label($journal->getMorphClass(), $journal->id))->toBe("Accounting journal #{$journal->id}")
        ->and($label(Borrower::class, 999991))->toBe('Member #999991')
        ->and($label(CoMaker::class, 999992))->toBe('Co-maker #999992')
        ->and($label(LoanApprovalStep::class, 999993))->toBe('Loan approval step #999993')
        ->and($label(GCashTransaction::class, 999994))->toBe('GCash transaction #999994');
});

it('lists a change inside a record that reads the same in words, with both amounts', function () {
    $row = AuditLog::create([
        'action' => 'updated',
        'user_id' => $this->admin->id,
        'old_values' => ['deductions' => [['name' => 'Processing Fee', 'amount' => 1500], ['name' => 'Notarial Fee', 'amount' => 100]]],
        'new_values' => ['deductions' => [['name' => 'Processing Fee', 'amount' => 2000], ['name' => 'Notarial Fee', 'amount' => 100]]],
    ]);

    $this->getJson("/api/audit-logs/{$row->id}")
        ->assertOk()
        ->assertJsonPath('data.changes', [[
            'field' => 'deductions',
            'old' => "Processing Fee \u{20B1}1,500.00, Notarial Fee \u{20B1}100.00",
            'new' => "Processing Fee \u{20B1}2,000.00, Notarial Fee \u{20B1}100.00",
        ]]);
});

it('lists a change inside an associative array that reads the same in words', function () {
    $row = AuditLog::create([
        'action' => 'updated',
        'user_id' => $this->admin->id,
        'old_values' => ['settings' => ['grace_days' => 3, 'rate' => 2]],
        'new_values' => ['settings' => ['grace_days' => 5, 'rate' => 2]],
    ]);

    $this->getJson("/api/audit-logs/{$row->id}")
        ->assertOk()
        ->assertJsonPath('data.changes', [[
            'field' => 'settings',
            'old' => 'grace days: 3; rate: 2',
            'new' => 'grace days: 5; rate: 2',
        ]]);
});

it('does not list the same content in a different key order', function () {
    $row = new AuditLog([
        'action' => 'updated',
        'old_values' => [
            'settings' => ['grace_days' => 3, 'tiers' => ['min' => 1, 'max' => 9]],
            'deductions' => [['name' => 'Processing Fee', 'amount' => 1500]],
        ],
        'new_values' => [
            'deductions' => [['amount' => 1500, 'name' => 'Processing Fee']],
            'settings' => ['tiers' => ['max' => 9, 'min' => 1], 'grace_days' => 3],
        ],
    ]);

    expect((new AuditLogResource($row))->toArray(request())['changes'])->toBe([]);
});

it('reads an Eloquent edit of an array field in words on both sides, with the amounts when only an amount changed', function () {
    $loan = Loan::factory()->create(['deductions' => [
        ['name' => 'Processing Fee', 'amount' => 1500, 'type' => 'fixed'],
        ['name' => 'Notarial Fee', 'amount' => 100, 'type' => 'fixed'],
    ]]);
    $loan->update(['deductions' => [
        ['name' => 'Processing Fee', 'amount' => 2000, 'type' => 'fixed'],
        ['name' => 'Notarial Fee', 'amount' => 100, 'type' => 'fixed'],
    ]]);
    $row = latestAuditRow('updated', $loan->getMorphClass(), $loan->id);
    expect($row->new_values['deductions'])->toBeString();

    $change = collect($this->getJson("/api/audit-logs/{$row->id}")->assertOk()->json('data.changes'))->firstWhere('field', 'deductions');

    expect($change)->toBe([
        'field' => 'deductions',
        'old' => "Processing Fee \u{20B1}1,500.00, Notarial Fee \u{20B1}100.00",
        'new' => "Processing Fee \u{20B1}2,000.00, Notarial Fee \u{20B1}100.00",
    ]);

    $product = LoanProduct::factory()->create(['frequencies' => ['monthly']]);
    $product->update(['frequencies' => ['monthly', 'weekly']]);
    $productRow = latestAuditRow('updated', $product->getMorphClass(), $product->id);

    expect(collect($this->getJson("/api/audit-logs/{$productRow->id}")->assertOk()->json('data.changes'))->firstWhere('field', 'frequencies'))
        ->toBe(['field' => 'frequencies', 'old' => 'monthly', 'new' => 'monthly, weekly']);
});

it('prints a value in pesos only for a fixed record, and never a percentage', function () {
    $shown = function (array $old, array $new): array {
        $row = AuditLog::create(['action' => 'updated', 'user_id' => $this->admin->id, 'old_values' => ['fees' => $old], 'new_values' => ['fees' => $new]]);
        $change = $this->getJson("/api/audit-logs/{$row->id}")->assertOk()->json('data.changes.0');

        return [$change['old'], $change['new']];
    };

    expect($shown([['name' => 'Late Fee', 'type' => 'fixed', 'value' => 50]], [['name' => 'Late Fee', 'type' => 'fixed', 'value' => 75]]))
        ->toBe(["Late Fee \u{20B1}50.00", "Late Fee \u{20B1}75.00"]);

    foreach ([
        [[['name' => 'Late Fee', 'type' => 'percentage', 'value' => 2]], [['name' => 'Late Fee', 'type' => 'percentage', 'value' => 3]]],
        [[['name' => 'Processing Fee', 'type' => 'percentage', 'amount' => 2]], [['name' => 'Processing Fee', 'type' => 'percentage', 'amount' => 3]]],
    ] as [$old, $new]) {
        [$oldShown, $newShown] = $shown($old, $new);
        expect($oldShown)->not->toContain("\u{20B1}")->toContain('2')
            ->and($newShown)->not->toContain("\u{20B1}")->toContain('3');
    }
});

it('does not list a number stored in another form', function () {
    $row = new AuditLog([
        'action' => 'updated',
        'old_values' => ['count' => 5, 'amount' => 1500, 'fee' => 1500, 'terms' => ['rate' => '2.50']],
        'new_values' => ['count' => '5', 'amount' => 1500.0, 'fee' => '1500.0', 'terms' => ['rate' => 2.5]],
    ]);

    expect((new AuditLogResource($row))->toArray(request())['changes'])->toBe([]);
});

it('lists a digit string that changed, such as a mobile number losing its leading zero', function () {
    $member = Borrower::factory()->create(['contact_number' => '09171234567']);
    $member->update(['contact_number' => '9171234567']);
    $row = latestAuditRow('updated', $member->getMorphClass(), $member->id);

    expect(collect($this->getJson("/api/audit-logs/{$row->id}")->assertOk()->json('data.changes'))->firstWhere('field', 'contact_number'))
        ->toBe(['field' => 'contact_number', 'old' => '09171234567', 'new' => '9171234567']);

    $padded = new AuditLog([
        'action' => 'updated',
        'old_values' => ['code' => '007', 'reference' => '1234567890123456789', 'spaced' => ' 5'],
        'new_values' => ['code' => '7', 'reference' => '1234567890123456780', 'spaced' => '5'],
    ]);

    expect(collect((new AuditLogResource($padded))->toArray(request())['changes'])->pluck('field')->sort()->values()->all())
        ->toBe(['code', 'reference', 'spaced']);
});

it('shows the peso amounts of a stored percentage deduction', function () {
    $loan = Loan::factory()->create(['deductions' => [
        ['name' => 'Processing Fee', 'amount' => 300, 'type' => 'percentage', 'original_value' => 3],
    ]]);
    $loan->update(['deductions' => [
        ['name' => 'Processing Fee', 'amount' => 360, 'type' => 'percentage', 'original_value' => 3],
    ]]);
    $row = latestAuditRow('updated', $loan->getMorphClass(), $loan->id);

    expect(collect($this->getJson("/api/audit-logs/{$row->id}")->assertOk()->json('data.changes'))->firstWhere('field', 'deductions'))
        ->toBe(['field' => 'deductions', 'old' => "Processing Fee \u{20B1}300.00", 'new' => "Processing Fee \u{20B1}360.00"]);
});
