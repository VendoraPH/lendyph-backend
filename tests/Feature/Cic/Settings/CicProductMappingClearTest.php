<?php

use App\Models\AuditLog;
use App\Models\CicProductMapping;
use App\Services\Cic\CicAccess;
use Tests\Feature\Cic\Records\CicRecordsFixtures;
use Tests\TestCase;

uses(TestCase::class, CicRecordsFixtures::class);

/*
 * DELETE /cic/product-mappings/{loan_product_id}: clears a product's CIC
 * mapping, audited in words; its loans then report the unmapped-product error.
 */

beforeEach(function () {
    $this->cicOn();
    $this->admin = $this->cicUser([CicAccess::VIEW, CicAccess::SETTINGS, 'branches:view_all']);
});

it('clears a mapping, audits it in words, and its loans then show the unmapped-product error', function () {
    $product = $this->cicProduct('CI', ['name' => 'Salary Loan']);
    $loan = $this->cicLoan(product: $product);

    $issues = fn () => collect($this->actingAs($this->admin)->getJson('/api/cic/issues?reference_date=2026-11-30&record_type=CI')->assertOk()->json('data'))
        ->where('record_key', $loan->loan_account_number)->pluck('code')->all();
    expect($issues())->not->toContain('UNMAPPED_PRODUCT');

    $response = $this->actingAs($this->admin)->deleteJson("/api/cic/product-mappings/{$product->id}")
        ->assertOk()->assertJsonPath('message', 'CIC product mapping cleared.');

    expect(collect($response->json('data.products'))->firstWhere('loan_product.id', $product->id)['mapping'])->toBeNull()
        ->and(CicProductMapping::query()->where('loan_product_id', $product->id)->exists())->toBeFalse()
        ->and($issues())->toContain('UNMAPPED_PRODUCT');

    $audit = AuditLog::query()->where('auditable_type', CicProductMapping::class)->where('action', 'deleted')->sole();
    expect($audit->user_id)->toBe($this->admin->id)
        ->and($audit->old_values)->toMatchArray(['loan_product' => 'Salary Loan', 'record_type' => 'CI', 'contract_type' => '12 Personal Loan'])
        ->and($audit->new_values)->toBeNull()
        ->and($audit->description)->toStartWith('CIC mapping for loan product "Salary Loan" cleared (was record type CI; contract type 12 Personal Loan; transaction type NA');

    $this->actingAs($this->admin)->deleteJson("/api/cic/product-mappings/{$product->id}")
        ->assertNotFound()->assertJsonPath('message', 'This loan product has no CIC mapping to clear.');
});

it('needs cic:settings, and answers 404 while CIC is off', function () {
    $product = $this->cicProduct();

    $this->actingAs($this->cicUser([CicAccess::VIEW, CicAccess::PREPARE, 'branches:view_all']))
        ->deleteJson("/api/cic/product-mappings/{$product->id}")->assertForbidden();
    $this->actingAs($this->cicUser([CicAccess::SETTINGS]))
        ->deleteJson("/api/cic/product-mappings/{$product->id}")->assertForbidden();

    config(['cic.enabled' => false]);
    $this->actingAs($this->admin)->deleteJson("/api/cic/product-mappings/{$product->id}")->assertNotFound();

    expect(CicProductMapping::query()->where('loan_product_id', $product->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('auditable_type', CicProductMapping::class)->exists())->toBeFalse();
});
