<?php

use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\SavingsHold;
use App\Models\SavingsIdempotencyKey;
use App\Models\SavingsInterestRun;
use App\Models\SavingsInterestRunLine;
use App\Models\SavingsProduct;
use App\Models\SavingsSetting;
use App\Models\SavingsTaxRule;
use App\Models\SavingsTransaction;
use App\Models\TimeDeposit;
use App\Models\User;
use App\Services\AuditLogModules;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

uses(TestCase::class, SetupLendyPH::class);

/*
 * The Audit Trail reads the new savings fields in words: money in ₱ with two
 * decimals, rates as a percent (never rounded), and enum keys in words, with
 * a holder type of non_member reading "Walk-in". Statuses stay raw for the
 * screen to map (round fz). Every savings row files under `savings`.
 */

beforeEach(function () {
    $this->seedAndLogin();
});

function savingsAuditChanges(string $type, ?array $old, ?array $new, int $id = 1): array
{
    $row = AuditLog::create([
        'action' => 'updated',
        'auditable_type' => $type,
        'auditable_id' => $id,
        'old_values' => $old,
        'new_values' => $new,
    ]);

    return collect(test()->getJson("/api/audit-logs/{$row->id}")->assertOk()->json('data.changes'))->keyBy('field')->all();
}

it('reads settings and product money in pesos', function () {
    $settings = savingsAuditChanges(SavingsSetting::class, ['tier1_limit' => '10000.00', 'large_transaction_alert' => '500000.00'], ['tier1_limit' => '15000.5', 'large_transaction_alert' => '750000.00']);
    $product = savingsAuditChanges(SavingsProduct::class, ['maintaining_balance' => '0.00', 'minimum_adb_for_interest' => '1000.00'], ['maintaining_balance' => '200.00', 'minimum_adb_for_interest' => '1500.50']);

    expect($settings['tier1_limit'])->toMatchArray(['old' => '₱10,000.00', 'new' => '₱15,000.50'])
        ->and($settings['large_transaction_alert']['new'])->toBe('₱750,000.00')
        ->and($product['maintaining_balance'])->toMatchArray(['old' => '₱0.00', 'new' => '₱200.00'])
        ->and($product['minimum_adb_for_interest']['new'])->toBe('₱1,500.50');
});

it('reads savings rates as a percent, never rounded', function () {
    $tax = savingsAuditChanges(SavingsTaxRule::class, ['rate' => '20.0000'], ['rate' => '12.3456']);
    $deposit = savingsAuditChanges(TimeDeposit::class, ['annual_rate' => '3.0000', 'withholding_tax_rate' => '0.0000'], ['annual_rate' => '3.2500', 'withholding_tax_rate' => '20.0000']);

    expect($tax['rate'])->toMatchArray(['old' => '20%', 'new' => '12.3456%'])
        ->and($deposit['annual_rate'])->toMatchArray(['old' => '3%', 'new' => '3.25%'])
        ->and($deposit['withholding_tax_rate'])->toMatchArray(['old' => '0%', 'new' => '20%']);
});

it('reads savings enum keys in words and leaves statuses raw', function () {
    $product = savingsAuditChanges(SavingsProduct::class, ['interest_crediting' => 'quarterly', 'kind' => 'regular'], ['interest_crediting' => 'semi_annual', 'kind' => 'time_deposit']);
    $tax = savingsAuditChanges(SavingsTaxRule::class, ['holder_type' => 'member'], ['holder_type' => 'non_member']);
    $deposit = savingsAuditChanges(TimeDeposit::class, ['maturity_instruction' => 'payout', 'funding_method' => 'cash'], ['maturity_instruction' => 'rollover_all', 'funding_method' => 'bank_transfer']);
    $account = savingsAuditChanges(SavingsAccount::class, ['status' => 'active', 'ledger_balance' => '0.00'], ['status' => 'dormant', 'ledger_balance' => '12.5']);

    expect($product['interest_crediting'])->toMatchArray(['old' => 'Quarterly', 'new' => 'Semi-Annual'])
        ->and($product['kind']['new'])->toBe('Time Deposit')
        ->and($tax['holder_type'])->toMatchArray(['old' => 'Member', 'new' => 'Walk-in'])
        ->and($deposit['maturity_instruction']['new'])->toBe('Roll Over Principal and Interest')
        ->and($deposit['funding_method']['new'])->toBe('Bank Transfer')
        ->and($account['status'])->toMatchArray(['old' => 'active', 'new' => 'dormant'])
        ->and($account['ledger_balance']['new'])->toBe('₱12.50');
});

it('names a savings account and a time deposit by their numbers', function () {
    $account = SavingsAccount::factory()->create(['account_number' => 'SAV-2026-000042']);
    $deposit = TimeDeposit::factory()->create(['certificate_number' => 'TD-2026-000007']);

    foreach ([[$account, 'SAV-2026-000042'], [$deposit, 'TD-2026-000007']] as [$model, $label]) {
        $row = AuditLog::create(['action' => 'updated', 'auditable_type' => $model::class, 'auditable_id' => $model->id, 'old_values' => ['remarks' => 'a'], 'new_values' => ['remarks' => 'b']]);

        $this->getJson("/api/audit-logs/{$row->id}")->assertOk()->assertJsonPath('data.target.label', $label);
    }
});

/*
 * Browser check (Phase 3, S5): a savings change list read "decided by: — → 5",
 * "result transaction id: — → 12" and "live key: rev:3 → —". People read by
 * name, transactions by their reference, accounts, products, time deposits
 * and interest runs by their number or name, and internal keys are left out.
 * Stored rows never change.
 */
it('names the people on a savings change list', function () {
    $ana = User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Reyes']);
    $ben = User::factory()->create(['first_name' => 'Ben', 'last_name' => 'Santos']);

    $approval = savingsAuditChanges(SavingsApproval::class,
        ['requested_by' => $ana->id, 'first_checker_id' => null, 'decided_by' => null],
        ['requested_by' => $ben->id, 'first_checker_id' => $ana->id, 'decided_by' => $ben->id]);
    $run = savingsAuditChanges(SavingsInterestRun::class,
        ['checked_by' => null, 'posted_by' => null, 'reversed_by' => null],
        ['checked_by' => $ana->id, 'posted_by' => $ben->id, 'reversed_by' => (string) $ana->id]);
    $deposit = savingsAuditChanges(TimeDeposit::class, ['settled_by' => null], ['settled_by' => $ben->id]);
    $settings = savingsAuditChanges(SavingsSetting::class, ['updated_by' => $ana->id], ['updated_by' => 999_999]);

    expect($approval['requested_by'])->toMatchArray(['old' => $ana->full_name, 'new' => $ben->full_name])
        ->and($approval['first_checker_id'])->toMatchArray(['old' => null, 'new' => $ana->full_name])
        ->and($approval['decided_by']['new'])->toBe($ben->full_name)
        ->and($run['checked_by']['new'])->toBe($ana->full_name)
        ->and($run['posted_by']['new'])->toBe($ben->full_name)
        ->and($run['reversed_by']['new'])->toBe($ana->full_name)
        ->and($deposit['settled_by']['new'])->toBe($ben->full_name)
        ->and($settings['updated_by'])->toMatchArray(['old' => $ana->full_name, 'new' => 'User #999999']);
});

it('reads savings transaction ids by their reference', function () {
    [$target, $result, $interest, $tax, $funding] = collect(['000012', '000013', '000014', '000015', '000016'])
        ->map(fn (string $n) => SavingsTransaction::factory()->create(['reference' => "SVT-20261110-{$n}"]))->all();

    $approval = savingsAuditChanges(SavingsApproval::class,
        ['target_transaction_id' => null, 'result_transaction_id' => null],
        ['target_transaction_id' => $target->id, 'result_transaction_id' => $result->id]);
    $line = savingsAuditChanges(SavingsInterestRunLine::class,
        ['interest_transaction_id' => null, 'tax_transaction_id' => null],
        ['interest_transaction_id' => $interest->id, 'tax_transaction_id' => $tax->id]);
    $deposit = savingsAuditChanges(TimeDeposit::class, ['funding_transaction_id' => null], ['funding_transaction_id' => $funding->id]);
    $transaction = savingsAuditChanges(SavingsTransaction::class,
        ['reverses_transaction_id' => null, 'counterpart_transaction_id' => null],
        ['reverses_transaction_id' => $target->id, 'counterpart_transaction_id' => 999_999]);

    expect($approval['target_transaction_id'])->toMatchArray(['old' => null, 'new' => 'SVT-20261110-000012'])
        ->and($approval['result_transaction_id']['new'])->toBe('SVT-20261110-000013')
        ->and($line['interest_transaction_id']['new'])->toBe('SVT-20261110-000014')
        ->and($line['tax_transaction_id']['new'])->toBe('SVT-20261110-000015')
        ->and($deposit['funding_transaction_id']['new'])->toBe('SVT-20261110-000016')
        ->and($transaction['reverses_transaction_id']['new'])->toBe('SVT-20261110-000012')
        ->and($transaction['counterpart_transaction_id']['new'])->toBe('Transaction #999999');
});

it('reads savings account, product, time deposit and run ids by their number or name', function () {
    $regular = SavingsProduct::factory()->create(['name' => 'Regular Savings']);
    $other = SavingsProduct::factory()->create(['name' => 'Kiddie Savings']);
    $account = SavingsAccount::factory()->create(['account_number' => 'SAV-2026-000042', 'product_id' => $regular->id]);
    $deposit = TimeDeposit::factory()->create(['certificate_number' => 'TD-2026-000007']);
    $run = SavingsInterestRun::factory()->create(['product_id' => $regular->id, 'period_start' => '2026-07-01', 'period_end' => '2026-09-30']);

    $approval = savingsAuditChanges(SavingsApproval::class,
        ['account_id' => null, 'time_deposit_id' => null],
        ['account_id' => $account->id, 'time_deposit_id' => $deposit->id]);
    $placement = savingsAuditChanges(TimeDeposit::class,
        ['payout_account_id' => null, 'rolled_over_from_id' => null, 'product_id' => $regular->id],
        ['payout_account_id' => $account->id, 'rolled_over_from_id' => $deposit->id, 'product_id' => $other->id]);
    $product = savingsAuditChanges(SavingsProduct::class, ['pretermination_product_id' => null], ['pretermination_product_id' => $regular->id]);
    $line = savingsAuditChanges(SavingsInterestRunLine::class, ['run_id' => null], ['run_id' => $run->id]);
    $transaction = savingsAuditChanges(SavingsTransaction::class, ['interest_run_id' => null], ['interest_run_id' => 999_999]);

    expect($approval['account_id']['new'])->toBe('SAV-2026-000042')
        ->and($approval['time_deposit_id']['new'])->toBe('TD-2026-000007')
        ->and($placement['payout_account_id']['new'])->toBe('SAV-2026-000042')
        ->and($placement['rolled_over_from_id']['new'])->toBe('TD-2026-000007')
        ->and($placement['product_id'])->toMatchArray(['old' => 'Regular Savings', 'new' => 'Kiddie Savings'])
        ->and($product['pretermination_product_id']['new'])->toBe('Regular Savings')
        ->and($line['run_id']['new'])->toBe("Interest run #{$run->id} (Jul 1, 2026 to Sep 30, 2026)")
        ->and($transaction['interest_run_id']['new'])->toBe('Interest run #999999');
});

it('leaves an id named like a savings one as stored on a row that is not savings', function () {
    $loan = savingsAuditChanges(Loan::class, ['account_id' => 4, 'run_id' => 5], ['account_id' => 6, 'run_id' => 7]);

    expect($loan['account_id'])->toMatchArray(['old' => '4', 'new' => '6'])
        ->and($loan['run_id'])->toMatchArray(['old' => '5', 'new' => '7']);
});

it('leaves internal savings keys out of the change list', function () {
    $approval = savingsAuditChanges(SavingsApproval::class, ['live_key' => 'rev:3', 'status' => 'pending'], ['live_key' => null, 'status' => 'approved']);
    $account = savingsAuditChanges(SavingsAccount::class, ['open_key' => '7:2', 'status' => 'active'], ['open_key' => null, 'status' => 'closed']);
    $run = savingsAuditChanges(SavingsInterestRun::class, ['live_key' => '2:1:2026-07-01'], ['live_key' => null]);
    $key = savingsAuditChanges(SavingsIdempotencyKey::class, ['request_hash' => str_repeat('a', 64)], ['request_hash' => str_repeat('b', 64)]);
    $hold = savingsAuditChanges(SavingsHold::class, ['status' => 'active'], ['status' => 'released']);

    expect($approval)->not->toHaveKey('live_key')->toHaveKey('status')
        ->and($account)->not->toHaveKey('open_key')->toHaveKey('status')
        ->and($run)->toBe([])
        ->and($key)->toBe([])
        ->and($hold)->toHaveKey('status');
});

it('files every savings model under savings', function (string $class) {
    expect(AuditLogModules::isMapped($class))->toBeTrue()
        ->and(AuditLogModules::for('updated', $class))->toBe('savings');
})->with([
    'App\\Models\\SavingsSetting',
    'App\\Models\\SavingsProduct',
    'App\\Models\\SavingsProductRate',
    'App\\Models\\SavingsTaxRule',
    'App\\Models\\SavingsAccount',
    'App\\Models\\SavingsTransaction',
    'App\\Models\\SavingsHold',
    'App\\Models\\SavingsApproval',
    'App\\Models\\TimeDeposit',
    'App\\Models\\SavingsInterestRun',
    'App\\Models\\SavingsInterestRunLine',
    'App\\Models\\SavingsIdempotencyKey',
]);
