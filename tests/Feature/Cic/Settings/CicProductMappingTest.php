<?php

use App\Models\AuditLog;
use App\Models\CicProductMapping;
use App\Models\LoanProduct;
use App\Services\Cic\CicAccess;
use Tests\Feature\Cic\Settings\CicTestKeys;
use Tests\TestCase;

uses(TestCase::class);

/*
 * GET and PUT /cic/product-mappings: every loan product with its CIC
 * classification; codes checked against the record type's own lists; audit
 * rows naming the product and the codes with their labels.
 */

beforeEach(function () {
    config(['cic.enabled' => true]);
    $this->admin = CicTestKeys::staff([CicAccess::SETTINGS]);
    $this->product = LoanProduct::factory()->create(['name' => 'Salary Loan']);
});

it('lists every loan product with its mapping or null, and the code lists', function () {
    $mapped = LoanProduct::factory()->create(['name' => 'Agri Loan']);
    CicProductMapping::factory()->create(['loan_product_id' => $mapped->id, 'record_type' => 'CI', 'contract_type' => '10', 'transaction_type' => 'AL', 'purpose_code' => '16']);

    $response = $this->actingAs(CicTestKeys::staff([CicAccess::VIEW]))->getJson('/api/cic/product-mappings')->assertOk();
    $rows = collect($response->json('data.products'))->keyBy('loan_product.id');

    expect($rows)->toHaveCount(LoanProduct::query()->count())
        ->and($rows[$this->product->id]['mapping'])->toBeNull()
        ->and($rows[$mapped->id]['mapping']['contract_type_label'])->toBe('Agricultural Loan')
        ->and($rows[$mapped->id]['mapping']['transaction_type_label'])->toBe('AGRICULTURAL LOAN')
        ->and($rows[$mapped->id]['mapping']['purpose_label'])->toBe('Other Agricultural Credit')
        ->and($response->json('data.code_lists'))->toHaveKeys(['CI:ContractTypeDomain', 'CN:ContractTypeDomain', 'TransactionTypeDomain', 'CreditPurposeDomain']);
});

it('saves a mapping and audits it in words, then audits only what changed', function () {
    $this->actingAs($this->admin)->putJson('/api/cic/product-mappings', ['mappings' => [
        ['loan_product_id' => $this->product->id, 'record_type' => 'CI', 'contract_type' => '20', 'transaction_type' => 'NA', 'purpose_code' => '31'],
    ]])->assertOk();

    $mapping = CicProductMapping::query()->sole();
    expect($mapping->only(['record_type', 'contract_type', 'transaction_type', 'purpose_code']))
        ->toBe(['record_type' => 'CI', 'contract_type' => '20', 'transaction_type' => 'NA', 'purpose_code' => '31'])
        ->and($mapping->updated_by)->toBe($this->admin->id);

    $created = AuditLog::query()->where('auditable_type', CicProductMapping::class)->sole();
    expect($created->action)->toBe('created')
        ->and($created->new_values['loan_product'])->toBe('Salary Loan')
        ->and($created->new_values['contract_type'])->toBe('20 Salary loan')
        ->and($created->description)->toContain('loan product "Salary Loan" set: record type CI; contract type 20 Salary loan');

    $this->actingAs($this->admin)->putJson('/api/cic/product-mappings', ['mappings' => [
        ['loan_product_id' => $this->product->id, 'record_type' => 'CI', 'contract_type' => '12', 'transaction_type' => 'NA', 'purpose_code' => '31'],
    ]])->assertOk();

    $updated = AuditLog::query()->where('auditable_type', CicProductMapping::class)->latest('id')->first();
    expect($updated->action)->toBe('updated')
        ->and($updated->old_values['contract_type'])->toBe('20 Salary loan')
        ->and($updated->new_values['contract_type'])->toBe('12 Personal Loan')
        ->and($updated->description)->toBe('CIC mapping for loan product "Salary Loan" changed: contract type 20 Salary loan → 12 Personal Loan.');

    $this->actingAs($this->admin)->putJson('/api/cic/product-mappings', ['mappings' => [
        ['loan_product_id' => $this->product->id, 'record_type' => 'CI', 'contract_type' => '12', 'transaction_type' => 'NA', 'purpose_code' => '31'],
    ]])->assertOk();

    expect(AuditLog::query()->where('auditable_type', CicProductMapping::class)->count())->toBe(2);
});

it('checks each code against the record type\'s own list', function (array $entry, string $field, string $message) {
    $this->actingAs($this->admin)->putJson('/api/cic/product-mappings', ['mappings' => [['loan_product_id' => $this->product->id, ...$entry]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["mappings.0.{$field}" => $message]);

    expect(CicProductMapping::query()->count())->toBe(0);
})->with([
    'a CN contract type on CI' => [['record_type' => 'CI', 'contract_type' => '48', 'transaction_type' => 'NA'], 'contract_type', '"48" isn\'t one of CIC\'s contract type codes for a CI contract.'],
    'a CI contract type on CN' => [['record_type' => 'CN', 'contract_type' => '12', 'transaction_type' => 'DL'], 'contract_type', '"12" isn\'t one of CIC\'s contract type codes for a CN contract.'],
    'an unknown transaction type' => [['record_type' => 'CI', 'contract_type' => '12', 'transaction_type' => 'XX'], 'transaction_type', '"XX" isn\'t one of CIC\'s transaction type codes for a CI contract.'],
    'an unknown purpose' => [['record_type' => 'CI', 'contract_type' => '12', 'transaction_type' => 'NA', 'purpose_code' => '99'], 'purpose_code', '"99" isn\'t one of CIC\'s purpose of credit codes for a CI contract.'],
    'a purpose on CN' => [['record_type' => 'CN', 'contract_type' => '48', 'transaction_type' => 'DL', 'purpose_code' => '31'], 'purpose_code', 'A CN contract has no purpose of credit; leave it empty.'],
    'a lower-case code' => [['record_type' => 'CN', 'contract_type' => '48', 'transaction_type' => 'dl'], 'transaction_type', '"dl" isn\'t one of CIC\'s transaction type codes for a CN contract.'],
    'another record type' => [['record_type' => 'CC', 'contract_type' => '30', 'transaction_type' => 'NA'], 'record_type', 'The record type is CI (instalment) or CN (non-instalment).'],
]);

it('refuses a missing product, a product listed twice and an empty list', function () {
    $entry = ['record_type' => 'CN', 'contract_type' => '48', 'transaction_type' => 'DL'];

    $this->actingAs($this->admin)->putJson('/api/cic/product-mappings', ['mappings' => [['loan_product_id' => 999999, ...$entry]]])
        ->assertUnprocessable()->assertJsonValidationErrors(['mappings.0.loan_product_id' => 'One of the loan products no longer exists. Reload the page.']);
    $this->actingAs($this->admin)->putJson('/api/cic/product-mappings', ['mappings' => [
        ['loan_product_id' => $this->product->id, ...$entry], ['loan_product_id' => $this->product->id, ...$entry],
    ]])->assertUnprocessable()->assertJsonValidationErrors('mappings.1.loan_product_id');
    $this->actingAs($this->admin)->putJson('/api/cic/product-mappings', ['mappings' => []])
        ->assertUnprocessable()->assertJsonPath('errors.mappings.0', 'List at least one loan product to map.');
});

it('needs cic:settings to save and cic:view to read', function () {
    $this->actingAs(CicTestKeys::staff([CicAccess::VIEW, CicAccess::PREPARE]))->putJson('/api/cic/product-mappings', ['mappings' => [
        ['loan_product_id' => $this->product->id, 'record_type' => 'CN', 'contract_type' => '48', 'transaction_type' => 'DL'],
    ]])->assertForbidden()->assertJsonPath('message', 'You don\'t have access to manage CIC settings.');

    $this->actingAs($this->admin)->getJson('/api/cic/product-mappings')
        ->assertForbidden()->assertJsonPath('message', 'You don\'t have access to view CIC reporting.');
});
