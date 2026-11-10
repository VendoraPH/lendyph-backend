<?php

use App\Models\AuditLog;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SavingsSetting;
use App\Models\SavingsTaxRule;
use App\Models\TimeDeposit;
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
