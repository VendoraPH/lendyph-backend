<?php

use App\Models\ApprovalWorkflowSetting;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->branch = Branch::first();
    $this->admin = User::where('username', 'super_admin')->first();
    $this->actingAs($this->admin);
});

it('returns default policy_exception chain when nothing saved', function () {
    $response = $this->getJson('/api/settings/approval-workflow?type=policy_exception')
        ->assertSuccessful();

    expect($response->json('data.type'))->toBe('policy_exception');
    expect($response->json('data.is_default'))->toBeTrue();
    expect($response->json('data.steps'))->toHaveCount(10);
});

it('returns default normal chain when nothing saved', function () {
    $response = $this->getJson('/api/settings/approval-workflow?type=normal')
        ->assertSuccessful();

    expect($response->json('data.type'))->toBe('normal');
    expect($response->json('data.is_default'))->toBeTrue();
    // Frontend PR #107: normal chain is now 4 steps ending in general_bookkeeper release
    expect($response->json('data.steps'))->toHaveCount(4);

    $steps = $response->json('data.steps');
    expect($steps[0]['role'])->toBe('loan_processor');
    expect($steps[0]['kind'])->toBe('submit');
    expect($steps[1]['role'])->toBe('manager');
    expect($steps[1]['kind'])->toBe('approve');
    expect($steps[2]['role'])->toBe('bod1');
    expect($steps[2]['kind'])->toBe('approve');
    expect($steps[3]['role'])->toBe('general_bookkeeper');
    expect($steps[3]['kind'])->toBe('release');
});

it('creates general_bookkeeper role with loans:release permission', function () {
    $role = Role::where('name', 'general_bookkeeper')->first();
    expect($role)->not->toBeNull();
    expect($role->hasPermissionTo('loans:release'))->toBeTrue();
});

it('saves a custom approval chain', function () {
    $steps = [
        ['id' => 'lp', 'name' => 'Loan Processor', 'role' => 'loan_processor', 'kind' => 'submit'],
        ['id' => 'mgr', 'name' => 'Manager', 'role' => 'manager', 'kind' => 'approve'],
        ['id' => 'cash', 'name' => 'Cashier', 'role' => 'cashier', 'kind' => 'release'],
    ];

    $response = $this->putJson('/api/settings/approval-workflow', [
        'type' => 'normal',
        'steps' => $steps,
    ])->assertSuccessful();

    expect($response->json('data.steps'))->toHaveCount(3);
    expect($response->json('data.is_default'))->toBeFalse();

    $this->assertDatabaseHas('approval_workflow_settings', ['type' => 'normal']);
});

it('rejects chain without submit as first step', function () {
    $this->putJson('/api/settings/approval-workflow', [
        'type' => 'normal',
        'steps' => [
            ['id' => 'mgr', 'name' => 'Manager', 'role' => 'manager', 'kind' => 'approve'],
            ['id' => 'cash', 'name' => 'Cashier', 'role' => 'cashier', 'kind' => 'release'],
        ],
    ])->assertStatus(422);
});

it('rejects chain without release as last step', function () {
    $this->putJson('/api/settings/approval-workflow', [
        'type' => 'normal',
        'steps' => [
            ['id' => 'lp', 'name' => 'Loan Processor', 'role' => 'loan_processor', 'kind' => 'submit'],
            ['id' => 'mgr', 'name' => 'Manager', 'role' => 'manager', 'kind' => 'approve'],
        ],
    ])->assertStatus(422);
});

it('rejects duplicate step ids', function () {
    $this->putJson('/api/settings/approval-workflow', [
        'type' => 'normal',
        'steps' => [
            ['id' => 'lp', 'name' => 'Loan Processor', 'role' => 'loan_processor', 'kind' => 'submit'],
            ['id' => 'lp', 'name' => 'Loan Processor 2', 'role' => 'loan_processor', 'kind' => 'approve'],
            ['id' => 'cash', 'name' => 'Cashier', 'role' => 'cashier', 'kind' => 'release'],
        ],
    ])->assertStatus(422);
});

it('resets a saved chain back to default', function () {
    ApprovalWorkflowSetting::create([
        'type' => 'normal',
        'steps' => [
            ['id' => 'lp', 'name' => 'LP', 'role' => 'loan_processor', 'kind' => 'submit'],
            ['id' => 'cash', 'name' => 'Cashier', 'role' => 'cashier', 'kind' => 'release'],
        ],
    ]);

    $response = $this->deleteJson('/api/settings/approval-workflow?type=normal')
        ->assertSuccessful();

    expect($response->json('data.is_default'))->toBeTrue();
    expect($response->json('data.steps'))->toHaveCount(4);

    $this->assertDatabaseMissing('approval_workflow_settings', ['type' => 'normal']);
});

it('upserts when saving same type twice', function () {
    $first = [
        ['id' => 'lp', 'name' => 'LP', 'role' => 'loan_processor', 'kind' => 'submit'],
        ['id' => 'cash', 'name' => 'Cashier', 'role' => 'cashier', 'kind' => 'release'],
    ];
    $second = [
        ['id' => 'lp', 'name' => 'LP', 'role' => 'loan_processor', 'kind' => 'submit'],
        ['id' => 'mgr', 'name' => 'Manager', 'role' => 'manager', 'kind' => 'approve'],
        ['id' => 'cash', 'name' => 'Cashier', 'role' => 'cashier', 'kind' => 'release'],
    ];

    $this->putJson('/api/settings/approval-workflow', ['type' => 'normal', 'steps' => $first])->assertSuccessful();
    $this->putJson('/api/settings/approval-workflow', ['type' => 'normal', 'steps' => $second])->assertSuccessful();

    expect(ApprovalWorkflowSetting::where('type', 'normal')->count())->toBe(1);
    expect(ApprovalWorkflowSetting::where('type', 'normal')->first()->steps)->toHaveCount(3);
});

it('saves and returns each step status label', function () {
    $steps = [
        ['id' => 'lp', 'name' => 'Loan Processor', 'role' => 'loan_processor', 'kind' => 'submit', 'status' => 'Submitted'],
        ['id' => 'mgr', 'name' => 'Manager', 'role' => 'manager', 'kind' => 'approve', 'status' => 'Credit Review'],
        ['id' => 'cash', 'name' => 'Cashier', 'role' => 'cashier', 'kind' => 'release', 'status' => 'Ready for Release'],
    ];

    $saved = $this->putJson('/api/settings/approval-workflow', [
        'type' => 'normal',
        'steps' => $steps,
    ])->assertSuccessful();

    expect(collect($saved->json('data.steps'))->pluck('status')->all())
        ->toBe(['Submitted', 'Credit Review', 'Ready for Release']);

    $fetched = $this->getJson('/api/settings/approval-workflow?type=normal')->assertSuccessful();

    expect($fetched->json('data.is_default'))->toBeFalse();
    expect(collect($fetched->json('data.steps'))->pluck('status')->all())
        ->toBe(['Submitted', 'Credit Review', 'Ready for Release']);
});

it('rejects a step status label longer than 100 characters', function () {
    $this->putJson('/api/settings/approval-workflow', [
        'type' => 'normal',
        'steps' => [
            ['id' => 'lp', 'name' => 'Loan Processor', 'role' => 'loan_processor', 'kind' => 'submit'],
            ['id' => 'mgr', 'name' => 'Manager', 'role' => 'manager', 'kind' => 'approve', 'status' => str_repeat('a', 101)],
            ['id' => 'cash', 'name' => 'Cashier', 'role' => 'cashier', 'kind' => 'release'],
        ],
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['steps.1.status']);
});

it('saves a chain where some steps have no status label', function () {
    $this->putJson('/api/settings/approval-workflow', [
        'type' => 'normal',
        'steps' => [
            ['id' => 'lp', 'name' => 'Loan Processor', 'role' => 'loan_processor', 'kind' => 'submit'],
            ['id' => 'mgr', 'name' => 'Manager', 'role' => 'manager', 'kind' => 'approve', 'status' => null],
            ['id' => 'cash', 'name' => 'Cashier', 'role' => 'cashier', 'kind' => 'release', 'status' => 'Ready for Release'],
        ],
    ])->assertSuccessful();

    $steps = ApprovalWorkflowSetting::where('type', 'normal')->first()->steps;

    expect($steps)->toHaveCount(3);
    expect($steps[0]['status'] ?? null)->toBeNull();
    expect($steps[1]['status'] ?? null)->toBeNull();
    expect($steps[2]['status'] ?? null)->toBe('Ready for Release');
});

it('audits saving a loan chain in words, old and new steps by name and role', function () {
    $steps = [
        ['id' => 'lp', 'name' => 'Loan Processor', 'role' => 'loan_processor', 'kind' => 'submit'],
        ['id' => 'mgr', 'name' => 'Branch Head', 'role' => 'manager', 'kind' => 'approve'],
        ['id' => 'cash', 'name' => 'Teller', 'role' => 'cashier', 'kind' => 'release'],
    ];

    $this->putJson('/api/settings/approval-workflow', ['type' => 'normal', 'steps' => $steps])->assertSuccessful();

    $rows = AuditLog::where('action', 'loan_approval_chain_updated')->get();
    expect($rows)->toHaveCount(1);
    expect($rows[0]->description)->toBe('Normal loan approval chain saved: Loan Processor (Loan Processor), Branch Head (Manager), Teller (Cashier). Before: the default chain, Loan Processor (Loan Processor), Manager (Manager), BOD Chairwoman (Bod1), General Bookkeeper (General Bookkeeper).');
    expect($rows[0]->old_values['type'])->toBe('normal');
    expect($rows[0]->old_values['is_default'])->toBeTrue();
    expect(array_column($rows[0]->old_values['steps'], 'name'))->toBe(array_column(ApprovalWorkflowSetting::defaultStepsFor('normal'), 'name'));
    expect($rows[0]->new_values['is_default'])->toBeFalse();
    expect(array_column($rows[0]->new_values['steps'], 'role'))->toBe(['loan_processor', 'manager', 'cashier']);
    expect($rows[0]->user_id)->toBe($this->admin->id);

    // Saving the same chain again changes nothing and writes nothing.
    $this->putJson('/api/settings/approval-workflow', ['type' => 'normal', 'steps' => $steps])->assertSuccessful();
    expect(AuditLog::where('action', 'loan_approval_chain_updated')->count())->toBe(1);
});

it('audits resetting a saved loan chain, and writes nothing when none was saved', function () {
    $this->deleteJson('/api/settings/approval-workflow?type=policy_exception')->assertSuccessful();
    expect(AuditLog::where('action', 'loan_approval_chain_updated')->count())->toBe(0);

    $this->putJson('/api/settings/approval-workflow', ['type' => 'policy_exception', 'steps' => [
        ['id' => 'lp', 'name' => 'Loan Processor', 'role' => 'loan_processor', 'kind' => 'submit'],
        ['id' => 'cash', 'name' => 'Cashier', 'role' => 'cashier', 'kind' => 'release'],
    ]])->assertSuccessful();

    $this->deleteJson('/api/settings/approval-workflow?type=policy_exception')->assertSuccessful();

    $reset = AuditLog::where('action', 'loan_approval_chain_updated')->orderByDesc('id')->first();
    expect(AuditLog::where('action', 'loan_approval_chain_updated')->count())->toBe(2);
    expect($reset->description)->toStartWith('Policy exception loan approval chain reset to the default chain: Loan Processor (Loan Processor), Manager (Manager), ');
    expect($reset->description)->toEndWith('. Before: Loan Processor (Loan Processor), Cashier (Cashier).');
    expect(array_column($reset->old_values['steps'], 'name'))->toBe(['Loan Processor', 'Cashier']);
    expect($reset->new_values['is_default'])->toBeTrue();
    expect($reset->new_values['steps'])->toHaveCount(10);
});
