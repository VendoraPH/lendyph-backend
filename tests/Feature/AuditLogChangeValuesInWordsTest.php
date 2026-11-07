<?php

use App\Models\AccountingExpense;
use App\Models\AccountingJournal;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Collateral;
use App\Models\CollateralType;
use App\Models\Fee;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\LoanApprovalStep;
use App\Models\LoanProduct;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

uses(TestCase::class, SetupLendyPH::class);

/*
 * The values in an audit row's change list read in plain words, on the server:
 * money in pesos, centavo columns converted, booleans as Yes or No, timestamps
 * in Manila time, roles and branches by name, and enum keys in words. Display
 * only: old_values and new_values come back as stored, and a status keeps its
 * raw value because the frontend maps it.
 */

beforeEach(function () {
    $this->seedAndLogin();
});

/**
 * The row's change list, keyed by field.
 *
 * @param  class-string|null  $type
 * @return array<string, array{field: string, old: string|null, new: string|null}>
 */
function auditChangesFor(?string $type, ?array $old, ?array $new, string $action = 'updated'): array
{
    $row = AuditLog::create([
        'action' => $action,
        'auditable_type' => $type,
        'auditable_id' => $type === null ? null : 1,
        'old_values' => $old,
        'new_values' => $new,
    ]);

    return collect(test()->getJson("/api/audit-logs/{$row->id}")->assertOk()->json('data.changes'))
        ->keyBy('field')
        ->all();
}

it('reads money in pesos with two decimals, whatever form it was stored in', function () {
    $changes = auditChangesFor(Loan::class, [
        'principal_amount' => 1500,
        'net_proceeds' => '1500.5',
        'total_deductions' => '2,000.00',
        'write_off_amount' => 0,
    ], [
        'principal_amount' => '2000.0',
        'net_proceeds' => 0,
        'total_deductions' => -25.5,
        'write_off_amount' => null,
    ]);

    expect($changes['principal_amount'])->toMatchArray(['old' => '₱1,500.00', 'new' => '₱2,000.00'])
        ->and($changes['net_proceeds'])->toMatchArray(['old' => '₱1,500.50', 'new' => '₱0.00'])
        ->and($changes['total_deductions'])->toMatchArray(['old' => '₱2,000.00', 'new' => '-₱25.50'])
        ->and($changes['write_off_amount'])->toMatchArray(['old' => '₱0.00', 'new' => null]);
});

it('reads money on a row a service wrote, by the field name, with or without a target', function () {
    $onLoan = auditChangesFor(Loan::class, ['outstanding_balance' => 1000], ['outstanding_balance' => 900.5, 'carried_premium' => 10, 'collected_at_release' => 20, 'interest_applied' => 5]);
    $noTarget = auditChangesFor(null, ['amount' => 1], ['amount' => 2, 'snapshot_value' => '3.5']);

    expect($onLoan['outstanding_balance'])->toMatchArray(['old' => '₱1,000.00', 'new' => '₱900.50'])
        ->and($onLoan['carried_premium']['new'])->toBe('₱10.00')
        ->and($onLoan['collected_at_release']['new'])->toBe('₱20.00')
        ->and($onLoan['interest_applied']['new'])->toBe('₱5.00')
        ->and($noTarget['amount'])->toMatchArray(['old' => '₱1.00', 'new' => '₱2.00'])
        ->and($noTarget['snapshot_value']['new'])->toBe('₱3.50');
});

it('keeps rates, counts, numbers and identifiers as stored, never in pesos', function () {
    $changes = auditChangesFor(Loan::class, [
        'interest_rate' => '2.5000',
        'penalty_rate' => '1',
        'term' => 6,
        'insurance_premium_pct' => '1.50',
        'contact_number' => '09171234567',
        'loan_account_number' => '100',
        'balance_adjustment_id' => 7,
    ], [
        'interest_rate' => '3.0000',
        'penalty_rate' => '2',
        'term' => 12,
        'insurance_premium_pct' => '2.50',
        'contact_number' => '09171234568',
        'loan_account_number' => '200',
        'balance_adjustment_id' => 8,
    ]);

    expect($changes['interest_rate']['new'])->toBe('3.0000')
        ->and($changes['penalty_rate']['new'])->toBe('2')
        ->and($changes['term']['new'])->toBe('12')
        ->and($changes['insurance_premium_pct']['new'])->toBe('2.50')
        ->and($changes['contact_number']['new'])->toBe('09171234568')
        ->and($changes['loan_account_number']['new'])->toBe('200')
        ->and($changes['balance_adjustment_id']['new'])->toBe('8');
});

it('reads a product fee rate as a percent, its notarial fee included, never in pesos', function () {
    $changes = auditChangesFor(LoanProduct::class, ['processing_fee' => '2.5000', 'notarial_fee' => '1.0000'], ['processing_fee' => '3.0000', 'notarial_fee' => '1.5000']);

    expect($changes['processing_fee']['new'])->toBe('3%')
        ->and($changes['notarial_fee'])->toMatchArray(['old' => '1%', 'new' => '1.5%']);
});

it('converts a journal total stored in centavos, so one peso never reads 100', function () {
    $changes = auditChangesFor(AccountingJournal::class, ['total_debit' => 100, 'total_credit' => '150050'], ['total_debit' => 250, 'total_credit' => 0]);

    expect($changes['total_debit'])->toMatchArray(['old' => '₱1.00', 'new' => '₱2.50'])
        ->and($changes['total_credit'])->toMatchArray(['old' => '₱1,500.50', 'new' => '₱0.00']);
});

it('converts the other columns stored in centavos', function () {
    $expense = auditChangesFor(AccountingExpense::class, ['amount' => 150000], ['amount' => 200000]);
    $loan = auditChangesFor(Loan::class, ['share_capital_centavos' => 100], ['share_capital_centavos' => 250]);

    expect($expense['amount'])->toMatchArray(['old' => '₱1,500.00', 'new' => '₱2,000.00'])
        ->and($loan['share_capital_centavos'])->toMatchArray(['old' => '₱1.00', 'new' => '₱2.50']);
});

it('still lists a real money change that reads the same at two decimals', function () {
    $changes = auditChangesFor(Loan::class, ['principal_amount' => '1500.001'], ['principal_amount' => '1500.002']);

    expect($changes)->toHaveKey('principal_amount')
        ->and($changes['principal_amount']['old'])->not->toBe($changes['principal_amount']['new']);
});

it('reads booleans as Yes or No, in every stored form', function () {
    $role = auditChangesFor(Role::class, ['is_active' => true], ['is_active' => false]);
    $loan = auditChangesFor(Loan::class, ['auto_pay' => 0, 'policy_exception' => '1'], ['auto_pay' => 1, 'policy_exception' => '0']);
    $service = auditChangesFor(Loan::class, null, ['co_maker_created' => true], 'co_maker_added');

    expect($role['is_active'])->toMatchArray(['old' => 'Yes', 'new' => 'No'])
        ->and($loan['auto_pay'])->toMatchArray(['old' => 'No', 'new' => 'Yes'])
        ->and($loan['policy_exception'])->toMatchArray(['old' => 'Yes', 'new' => 'No'])
        ->and($service['co_maker_created']['new'])->toBe('Yes');
});

it('does not list a boolean stored in another form', function () {
    expect(auditChangesFor(Role::class, ['is_active' => true], ['is_active' => 1]))->not->toHaveKey('is_active');
});

it('reads timestamps in Manila time, whichever form they were stored in', function () {
    $changes = auditChangesFor(Loan::class, [
        'released_at' => '2026-11-01T02:30:00.000000Z',
        'approved_at' => '2026-11-01 14:05:00',
        'rejected_at' => null,
    ], [
        'released_at' => '2026-11-01T16:00:00+00:00',
        'approved_at' => '2026-11-02 09:00:00',
        'rejected_at' => 'not a date',
    ]);

    expect($changes['released_at'])->toMatchArray(['old' => 'Nov 1, 2026, 10:30 AM', 'new' => 'Nov 2, 2026, 12:00 AM'])
        ->and($changes['approved_at'])->toMatchArray(['old' => 'Nov 1, 2026, 2:05 PM', 'new' => 'Nov 2, 2026, 9:00 AM'])
        ->and($changes['rejected_at'])->toMatchArray(['old' => null, 'new' => 'not a date']);
});

it('reads a date column as its Manila date, with no time', function () {
    // A date cast sits at midnight Manila, which JSON writes as the day before in UTC.
    $changes = auditChangesFor(Loan::class, ['maturity_date' => '2026-10-31T16:00:00.000000Z'], ['maturity_date' => '2026-12-15']);

    expect($changes['maturity_date'])->toMatchArray(['old' => 'Nov 1, 2026', 'new' => 'Dec 15, 2026']);
});

it('does not list the same moment or date written two ways', function () {
    $changes = auditChangesFor(Loan::class, [
        'released_at' => '2026-11-01T02:30:00.000000Z',
        'maturity_date' => '2026-10-31T16:00:00.000000Z',
    ], [
        'released_at' => '2026-11-01 10:30:00',
        'maturity_date' => '2026-11-01',
        'status' => 'ongoing',
    ]);

    expect(array_keys($changes))->toBe(['status']);
});

it('does not list one moment written to a fraction of the same second', function () {
    $changes = auditChangesFor(Loan::class, ['released_at' => '2026-11-01T02:30:00.500000Z'], ['released_at' => '2026-11-01 10:30:00', 'status' => 'ongoing']);

    expect(array_keys($changes))->toBe(['status']);
});

it('still lists a real timestamp change within one minute, with the seconds', function () {
    $changes = auditChangesFor(Loan::class, ['released_at' => '2026-11-01 10:30:05'], ['released_at' => '2026-11-01T02:30:40.000000Z']);

    expect($changes['released_at'])->toMatchArray(['old' => 'Nov 1, 2026, 10:30:05 AM', 'new' => 'Nov 1, 2026, 10:30:40 AM']);
});

it('reads a role by its audit name, a removed role included', function () {
    $role = Role::create(['name' => 'teller_probe', 'guard_name' => 'web', 'display_name' => 'Teller', 'is_system' => false]);

    $changes = auditChangesFor(User::class, ['role' => 'teller_probe'], ['role' => 'gone_role']);

    expect($changes['role'])->toMatchArray(['old' => $role->auditName(), 'new' => '"Gone Role" (gone_role)'])
        ->and($changes['role']['old'])->toBe('"Teller" (teller_probe)');
});

it('reads branch ids by branch name, a removed branch by its id', function () {
    $first = Branch::factory()->create(['name' => 'Alpha Branch']);
    $second = Branch::factory()->create(['name' => 'Beta Branch']);
    $missing = $second->id + 1000;

    $changes = auditChangesFor(User::class, ['branch_ids' => [$first->id], 'branch_id' => $first->id], ['branch_ids' => [$first->id, $second->id, $missing], 'branch_id' => $second->id]);

    expect($changes['branch_ids'])->toMatchArray(['old' => 'Alpha Branch', 'new' => "Alpha Branch, Beta Branch, Branch #{$missing}"])
        ->and($changes['branch_id'])->toMatchArray(['old' => 'Alpha Branch', 'new' => 'Beta Branch']);
});

it('reads enum keys in words', function () {
    $loan = auditChangesFor(Loan::class, ['frequency' => 'monthly', 'term_unit' => 'months', 'interest_rate_frequency' => 'bi_weekly'], ['frequency' => 'semi_monthly', 'term_unit' => 'days', 'interest_rate_frequency' => 'semi_monthly']);
    $product = auditChangesFor(LoanProduct::class, ['frequencies' => ['monthly'], 'interest_method' => 'straight'], ['frequencies' => json_encode(['bi_weekly', 'semi_monthly', 'upon_maturity']), 'interest_method' => 'upon_maturity']);
    $repayment = auditChangesFor(Loan::class, ['method' => 'cash', 'payment_type' => 'partial'], ['method' => 'gcash', 'payment_type' => 'advance']);

    expect($loan['frequency'])->toMatchArray(['old' => 'Monthly', 'new' => 'Semi-Monthly'])
        ->and($loan['term_unit'])->toMatchArray(['old' => 'Months', 'new' => 'Days'])
        ->and($loan['interest_rate_frequency'])->toMatchArray(['old' => 'Bi-Weekly', 'new' => 'Semi-Monthly'])
        ->and($product['frequencies'])->toMatchArray(['old' => 'Monthly', 'new' => 'Bi-Weekly, Semi-Monthly, Upon Maturity'])
        ->and($product['interest_method'])->toMatchArray(['old' => 'Straight', 'new' => 'Upon Maturity'])
        ->and($repayment['method'])->toMatchArray(['old' => 'Cash', 'new' => 'GCash'])
        ->and($repayment['payment_type'])->toMatchArray(['old' => 'Partial', 'new' => 'Advance']);
});

it('keeps a status raw, for the frontend to map', function () {
    $loan = auditChangesFor(Loan::class, ['status' => 'for_review'], ['status' => 'approved']);
    $journal = auditChangesFor(AccountingJournal::class, ['status' => 'draft'], ['status' => 'posted']);

    expect($loan['status'])->toMatchArray(['old' => 'for_review', 'new' => 'approved'])
        ->and($journal['status'])->toMatchArray(['old' => 'draft', 'new' => 'posted']);
});

it('returns the stored values exactly as they are', function () {
    $row = AuditLog::create([
        'action' => 'updated',
        'auditable_type' => AccountingJournal::class,
        'auditable_id' => 1,
        'old_values' => ['total_debit' => 100],
        'new_values' => ['total_debit' => 250],
    ]);

    $this->getJson("/api/audit-logs/{$row->id}")
        ->assertOk()
        ->assertJsonPath('data.old_values.total_debit', 100)
        ->assertJsonPath('data.new_values.total_debit', 250);

    $stored = DB::table('audit_logs')->where('id', $row->id)->first();
    expect(json_decode($stored->new_values, true))->toBe(['total_debit' => 250]);
});

it('loads the role and branch names in one query each, whatever the page size', function () {
    $branch = Branch::first();

    foreach (range(1, 4) as $i) {
        AuditLog::create([
            'action' => 'updated',
            'auditable_type' => User::class,
            'auditable_id' => 1,
            'description' => 'names-probe',
            'old_values' => ['role' => 'admin', 'branch_ids' => [$branch->id]],
            'new_values' => ['role' => 'viewer', 'branch_ids' => []],
        ]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->getJson('/api/audit-logs?search=names-probe&per_page=4')->assertOk();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries->filter(fn (string $sql) => str_contains($sql, '`name` in') && str_contains($sql, 'from `roles`'))->count())->toBe(1)
        ->and($queries->filter(fn (string $sql) => str_contains($sql, 'from `branches`') && str_contains($sql, '`id` in'))->count())->toBe(1);
});

it('names both users when a user foreign key changed from one to another', function () {
    $first = User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Reyes']);
    $second = User::factory()->create(['first_name' => 'Ben', 'last_name' => 'Cruz']);

    $changes = auditChangesFor(Loan::class, ['approved_by' => $first->id], ['approved_by' => $second->id]);

    expect($changes['approved_by'])->toMatchArray(['old' => 'Ana Reyes', 'new' => 'Ben Cruz']);
});

it('uses the screen words for the target type in the CSV export', function () {
    foreach (['App\Models\CoMaker', 'App\Models\LoanApprovalStep', 'App\Models\BorrowerSubmissionToken', 'App\Models\GCashTransaction'] as $type) {
        AuditLog::create([
            'action' => 'updated',
            'user_id' => $this->admin->id,
            'auditable_type' => $type,
            'auditable_id' => 1,
            'description' => 'csv-words-probe',
        ]);
    }

    $csv = $this->get('/api/audit-logs/export?search=csv-words-probe', ['Accept' => 'application/json'])
        ->assertOk()
        ->streamedContent();

    $rows = collect(explode("\n", trim($csv)))->slice(1)->map(fn (string $line) => str_getcsv($line, escape: ''))->values();

    expect($rows->pluck(3)->all())->toBe(['Co-maker', 'Loan approval step', 'Member submission token', 'GCash transaction']);
});

it('says "1 branch" or "N branches" on a new user row', function (int $count, string $words) {
    $branches = Branch::factory()->count($count)->create();

    $this->postJson('/api/users', [
        'first_name' => 'Branch',
        'last_name' => 'Wording',
        'username' => 'branch_wording',
        'email' => 'branch.wording@example.test',
        'password' => 'Str0ng!Passw0rd#2026',
        'password_confirmation' => 'Str0ng!Passw0rd#2026',
        'role' => 'viewer',
        'branch_ids' => $branches->pluck('id')->all(),
    ])->assertCreated();

    $row = AuditLog::where('action', 'branches_assigned')->latest('id')->firstOrFail();

    expect($row->description)->toBe("User branch_wording created with {$words}");
})->with([
    'one' => [1, '1 branch'],
    'three' => [3, '3 branches'],
]);

it('reads money in a waiver or extension row in pesos, its own keys included', function () {
    $waiver = auditChangesFor(LoanAdjustment::class, null, ['total_waived' => 120.37, 'penalty_owed' => '59.44'], 'penalty_waived');
    $extension = auditChangesFor(LoanAdjustment::class, null, [
        'total_collected' => 750,
        'carried_principal' => 15000,
        'carry_interest' => 600,
        'fresh_interest' => '750.5',
        'cycles' => 2,
        'interest_option' => 'defer',
    ], 'periods_closed');

    expect($waiver['total_waived']['new'])->toBe('₱120.37')
        ->and($waiver['penalty_owed']['new'])->toBe('₱59.44')
        ->and($extension['total_collected']['new'])->toBe('₱750.00')
        ->and($extension['carried_principal']['new'])->toBe('₱15,000.00')
        ->and($extension['carry_interest']['new'])->toBe('₱600.00')
        ->and($extension['fresh_interest']['new'])->toBe('₱750.50')
        ->and($extension['cycles']['new'])->toBe('2')
        ->and($extension['interest_option']['new'])->toBe('Defer');
});

it('reads money inside an adjustment\'s own values in pesos when the detail is shown', function () {
    $changes = auditChangesFor(
        LoanAdjustment::class,
        ['new_values' => ['adjustment_amount' => -2500, 'reason' => 'Correction']],
        ['new_values' => json_encode(['adjustment_amount' => -3000, 'reason' => 'Correction'])],
    );

    expect($changes['new_values'])->toMatchArray([
        'old' => 'adjustment amount: -₱2,500.00; reason: Correction',
        'new' => 'adjustment amount: -₱3,000.00; reason: Correction',
    ]);
});

it('reads a fee\'s value by each side\'s own type', function () {
    $toFixed = auditChangesFor(Fee::class, ['id' => 3, 'type' => 'percentage', 'value' => '1.2500'], ['type' => 'fixed', 'value' => 150]);
    $sameType = auditChangesFor(Fee::class, ['id' => 3, 'type' => 'formula', 'value' => '2.5000'], ['value' => '3.0000']);
    $fixed = auditChangesFor(Fee::class, ['id' => 3, 'type' => 'fixed', 'value' => '500.0000'], ['value' => '450.5']);

    expect($toFixed['value'])->toMatchArray(['old' => '1.25%', 'new' => '₱150.00'])
        ->and($toFixed['type'])->toMatchArray(['old' => 'Percentage', 'new' => 'Fixed'])
        ->and($sameType['value'])->toMatchArray(['old' => '2.5%', 'new' => '3%'])
        ->and($fixed['value'])->toMatchArray(['old' => '₱500.00', 'new' => '₱450.50']);
});

it('keeps a fee\'s value as stored when that side\'s type is not known', function () {
    $changes = auditChangesFor(Fee::class, ['value' => '1.2500'], ['value' => 2]);
    $unknownType = auditChangesFor(Fee::class, ['type' => 'tiered', 'value' => '1.2500'], ['value' => 2]);

    expect($changes['value'])->toMatchArray(['old' => '1.2500', 'new' => '2'])
        ->and($unknownType['value'])->toMatchArray(['old' => '1.2500', 'new' => '2']);
});

it('reads products and the account officer by name, a removed one by its id', function () {
    $first = LoanProduct::factory()->create(['name' => 'Salary Loan']);
    $second = LoanProduct::factory()->create(['name' => 'Rice Loan']);
    $officer = User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Reyes']);
    $gone = $second->id + 1000;
    $goneUser = User::max('id') + 1000;

    $fee = auditChangesFor(Fee::class, ['applicable_product_ids' => [$first->id]], ['applicable_product_ids' => json_encode([$first->id, $second->id, $gone])]);
    $loan = auditChangesFor(Loan::class, ['loan_product_id' => $first->id, 'account_officer_id' => $goneUser], ['loan_product_id' => $gone, 'account_officer_id' => $officer->id]);

    expect($fee['applicable_product_ids'])->toMatchArray(['old' => 'Salary Loan', 'new' => "Salary Loan, Rice Loan, Product #{$gone}"])
        ->and($loan['loan_product_id'])->toMatchArray(['old' => 'Salary Loan', 'new' => "Product #{$gone}"])
        ->and($loan['account_officer_id'])->toMatchArray(['old' => "User #{$goneUser}", 'new' => 'Ana Reyes']);
});

it('loads the product names in one query, whatever the page size', function () {
    $product = LoanProduct::factory()->create();

    foreach (range(1, 4) as $i) {
        AuditLog::create([
            'action' => 'updated',
            'auditable_type' => Fee::class,
            'auditable_id' => 1,
            'description' => 'products-probe',
            'old_values' => ['applicable_product_ids' => [$product->id]],
            'new_values' => ['applicable_product_ids' => [], 'loan_product_id' => $product->id],
        ]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->getJson('/api/audit-logs?search=products-probe&per_page=4')->assertOk();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries->filter(fn (string $sql) => str_contains($sql, 'from `loan_products`'))->count())->toBe(1);
});

it('reads closed approval steps by their names on the loan, else as roles', function () {
    $loan = Loan::factory()->create();
    foreach ([['manager', 'Branch Manager', 'manager'], ['chairwoman', 'BOD Chairwoman', 'bod1']] as $order => [$stepId, $name, $role]) {
        LoanApprovalStep::create([
            'loan_id' => $loan->id, 'round' => 1, 'step_order' => $order + 1, 'step_id' => $stepId,
            'name' => $name, 'role' => $role, 'kind' => 'approve', 'status' => 'approved',
        ]);
    }

    $row = AuditLog::create([
        'action' => 'loan_chain_short_circuited',
        'auditable_type' => Loan::class,
        'auditable_id' => $loan->id,
        'new_values' => ['round' => 1, 'steps_closed' => ['manager', 'chairwoman']],
    ]);
    // No target loan, so no chain to read the names from (a target id of 1
    // could be the loan above).
    $noSteps = auditChangesFor(null, null, ['steps_closed' => ['manager', 'not_a_role']], 'loan_chain_short_circuited');

    $changes = collect($this->getJson("/api/audit-logs/{$row->id}")->assertOk()->json('data.changes'))->keyBy('field');

    expect($changes['steps_closed']['new'])->toBe('Branch Manager, BOD Chairwoman')
        ->and($noSteps['steps_closed']['new'])->toBe(Role::where('name', 'manager')->firstOrFail()->auditName().', Not A Role');
});

it('leaves a fee fingerprint out of the change list', function () {
    $changes = auditChangesFor(Loan::class, null, ['net_proceeds' => 4125, 'fee_fingerprint' => str_repeat('9f', 32)], 'release_fees');
    $fee = auditChangesFor(Fee::class, ['fingerprint' => 'abc'], ['fingerprint' => 'def', 'value' => 1]);

    expect($changes)->not->toHaveKey('fee_fingerprint')
        ->and($changes['net_proceeds']['new'])->toBe('₱4,125.00')
        ->and($fee)->not->toHaveKey('fingerprint');
});

it('reads a list of periods by number, with their peso figures when the lists read the same', function () {
    $changes = auditChangesFor(LoanAdjustment::class, ['periods' => [
        ['schedule_id' => 50, 'period_number' => 2, 'penalty_amount' => 59.44, 'penalty_paid' => 0],
        ['schedule_id' => 51, 'period_number' => 3, 'penalty_amount' => 60.93, 'penalty_paid' => 0],
    ]], ['periods' => [
        ['schedule_id' => 50, 'period_number' => 2, 'penalty_amount' => 0, 'penalty_paid' => 0, 'waived' => 59.44],
        ['schedule_id' => 51, 'period_number' => 3, 'penalty_amount' => 0, 'penalty_paid' => 0, 'waived' => 60.93],
    ], 'total_waived' => 120.37], 'penalty_waived');

    $fewer = auditChangesFor(LoanAdjustment::class, ['periods' => [['period_number' => 1, 'penalty_amount' => 5]]], ['periods' => [['period_number' => 1, 'penalty_amount' => 5], ['period_number' => 2, 'penalty_amount' => 7]]]);

    expect($changes['periods'])->toMatchArray([
        'old' => 'Period 2 (penalty amount ₱59.44, penalty paid ₱0.00); Period 3 (penalty amount ₱60.93, penalty paid ₱0.00)',
        'new' => 'Period 2 (penalty amount ₱0.00, penalty paid ₱0.00, waived ₱59.44); Period 3 (penalty amount ₱0.00, penalty paid ₱0.00, waived ₱60.93)',
    ])
        ->and($changes['total_waived']['new'])->toBe('₱120.37')
        ->and($fewer['periods'])->toMatchArray(['old' => 'Period 1', 'new' => 'Period 1, Period 2']);
});

it('keeps permissions as keys, for the frontend to read', function () {
    $changes = auditChangesFor(Role::class, ['permissions' => ['loans:view']], ['permissions' => ['loans:view', 'borrowers:view']], 'role_permissions_changed');

    expect($changes['permissions'])->toMatchArray(['old' => 'loans:view', 'new' => 'loans:view, borrowers:view']);
});

it('reads a single approval step id by the step\'s name on the loan', function () {
    $loan = Loan::factory()->create();
    LoanApprovalStep::create([
        'loan_id' => $loan->id, 'round' => 1, 'step_order' => 3, 'step_id' => 'general-bookkeeper',
        'name' => 'General Bookkeeper', 'role' => 'general_bookkeeper', 'kind' => 'release', 'status' => 'approved',
    ]);

    $row = AuditLog::create([
        'action' => 'loan_step_released',
        'auditable_type' => Loan::class,
        'auditable_id' => $loan->id,
        'new_values' => ['role' => 'general_bookkeeper', 'round' => 1, 'step_id' => 'general-bookkeeper', 'step_order' => 3],
    ]);

    $changes = collect($this->getJson("/api/audit-logs/{$row->id}")->assertOk()->json('data.changes'))->keyBy('field');

    expect($changes['step_id']['new'])->toBe('General Bookkeeper');
});

it('reads loan ids by the loan\'s number, a removed one by its id', function () {
    $released = Loan::factory()->create(['loan_account_number' => 'LN-PROBE-17']);
    $draft = Loan::factory()->create(['loan_account_number' => null]);
    // Set after the create: Loan::booted() numbers the application itself.
    DB::table('loans')->where('id', $draft->id)->update(['application_number' => 'LA-PROBE-18']);
    $gone = Loan::max('id') + 1000;

    $changes = auditChangesFor(Loan::class, ['source_loan_id' => $released->id], [
        'source_loan_id' => $gone,
        'restructured_into_loan_id' => $draft->id,
        'carried_from_loan_id' => $released->id,
    ], 'restructure_closed');

    expect($changes['source_loan_id'])->toMatchArray(['old' => 'LN-PROBE-17', 'new' => "Loan #{$gone}"])
        ->and($changes['restructured_into_loan_id']['new'])->toBe('LA-PROBE-18')
        ->and($changes['carried_from_loan_id']['new'])->toBe('LN-PROBE-17');
});

it('reads collaterals by their type and detail, a removed one by its id', function () {
    $type = CollateralType::create(['name' => 'Vehicle', 'detail_field_label' => 'Plate number', 'amount_field_label' => 'Value', 'source' => 'manual', 'display_order' => 99, 'is_visible' => true, 'is_seed' => false]);
    $member = Borrower::factory()->create();
    $car = Collateral::create(['borrower_id' => $member->id, 'collateral_type_id' => $type->id, 'detail_value' => 'ABC 1234', 'amount' => 100000]);
    $gone = $car->id + 1000;

    $changes = auditChangesFor(Loan::class, null, ['inherited_collateral_ids' => [$car->id, $gone]], 'restructure_created');
    $none = auditChangesFor(Loan::class, ['inherited_collateral_ids' => [$car->id]], ['inherited_collateral_ids' => []]);

    expect($changes['inherited_collateral_ids']['new'])->toBe("Vehicle: ABC 1234, Collateral #{$gone}")
        ->and($none['inherited_collateral_ids'])->toMatchArray(['old' => 'Vehicle: ABC 1234', 'new' => 'none']);
});

it('reads a loan product\'s rates as a percent, never rounded, and its build-up range in pesos', function () {
    $changes = auditChangesFor(LoanProduct::class, [
        'interest_rate' => '3.0000', 'min_interest_rate' => '1.2500', 'processing_fee' => '0.0000',
        'min_processing_fee' => '1.0000', 'max_processing_fee' => '5.0000', 'service_fee' => '1.0000',
        'min_service_fee' => '0.5000', 'max_service_fee' => '2.0000', 'notarial_fee' => '1.0000',
        'penalty_rate' => '2.0000', 'min_scb' => '0.00', 'max_scb' => '0.00',
    ], [
        'interest_rate' => 3.125, 'min_interest_rate' => '1.3333', 'processing_fee' => 1.5,
        'min_processing_fee' => 1.25, 'max_processing_fee' => 10, 'service_fee' => 2,
        'min_service_fee' => 0.75, 'max_service_fee' => 3, 'notarial_fee' => 1.5,
        'penalty_rate' => 2.5, 'min_scb' => 100, 'max_scb' => 500,
    ]);

    expect($changes['processing_fee'])->toMatchArray(['old' => '0%', 'new' => '1.5%'])
        ->and($changes['interest_rate'])->toMatchArray(['old' => '3%', 'new' => '3.125%'])
        ->and($changes['min_interest_rate'])->toMatchArray(['old' => '1.25%', 'new' => '1.3333%'])
        ->and($changes['min_processing_fee']['new'])->toBe('1.25%')
        ->and($changes['max_processing_fee']['new'])->toBe('10%')
        ->and($changes['service_fee']['new'])->toBe('2%')
        ->and($changes['min_service_fee']['new'])->toBe('0.75%')
        ->and($changes['max_service_fee']['new'])->toBe('3%')
        ->and($changes['notarial_fee'])->toMatchArray(['old' => '1%', 'new' => '1.5%'])
        ->and($changes['penalty_rate'])->toMatchArray(['old' => '2%', 'new' => '2.5%'])
        ->and($changes['min_scb'])->toMatchArray(['old' => '₱0.00', 'new' => '₱100.00'])
        ->and($changes['max_scb']['new'])->toBe('₱500.00');
});

it('loads the loan and collateral names in one query each, whatever the page size', function () {
    $loan = Loan::factory()->create();

    foreach (range(1, 4) as $i) {
        AuditLog::create([
            'action' => 'restructure_created',
            'auditable_type' => Loan::class,
            'auditable_id' => $loan->id,
            'description' => 'loans-probe',
            'new_values' => ['source_loan_id' => $loan->id, 'inherited_collateral_ids' => [1]],
        ]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->getJson('/api/audit-logs?search=loans-probe&per_page=4')->assertOk();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    // The loans' numbers; the rows' own targets load separately, as they always have.
    expect($queries->filter(fn (string $sql) => str_contains($sql, '`loan_account_number`, `application_number` from `loans`'))->count())->toBe(1)
        ->and($queries->filter(fn (string $sql) => str_contains($sql, 'from `collaterals`'))->count())->toBe(1);
});

it('names the branches in a new branch change row\'s description', function () {
    $main = Branch::first();
    $other = Branch::factory()->create(['name' => 'Cabadbaran']);
    $user = User::factory()->create(['branch_id' => $main->id]);
    $user->branches()->sync([$main->id]);

    $this->putJson("/api/users/{$user->id}", ['branch_ids' => [$main->id, $other->id]])->assertOk();

    $row = AuditLog::where('action', 'branches_changed')->where('auditable_id', $user->id)->latest('id')->firstOrFail();

    expect($row->description)->toBe("Branch assignment for {$user->username} changed from {$main->name} to {$main->name}, Cabadbaran");
});
