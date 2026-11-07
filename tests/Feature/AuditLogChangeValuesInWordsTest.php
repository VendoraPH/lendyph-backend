<?php

use App\Models\AccountingExpense;
use App\Models\AccountingJournal;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Loan;
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

it('reads a product fee rate as stored, its notarial fee included, never in pesos', function () {
    $changes = auditChangesFor(LoanProduct::class, ['processing_fee' => '2.5000', 'notarial_fee' => '1.0000'], ['processing_fee' => '3.0000', 'notarial_fee' => '1.5000']);

    expect($changes['processing_fee']['new'])->toBe('3.0000')
        ->and($changes['notarial_fee'])->toMatchArray(['old' => '1.0000', 'new' => '1.5000']);
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
