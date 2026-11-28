<?php

use App\Models\AuditLog;
use App\Models\CicLoanOverride;
use App\Models\CicProductMapping;
use App\Models\Loan;
use App\Services\Cic\CicAccess;
use Tests\Feature\Cic\Settings\CicTestKeys;
use Tests\TestCase;

uses(TestCase::class);

/*
 * /cic/loan-overrides: a reportable loan's own classification with a reason,
 * codes checked against the record type the loan reports as, every write
 * audited by the loan's display number.
 */

beforeEach(function () {
    config(['cic.enabled' => true]);
    $this->preparer = CicTestKeys::staff([CicAccess::PREPARE]);
    $this->loan = Loan::factory()->create(['status' => 'released', 'loan_account_number' => 'LN-CIC-0001']);
    CicProductMapping::factory()->create(['loan_product_id' => $this->loan->loan_product_id, 'record_type' => 'CI', 'contract_type' => '12', 'transaction_type' => 'NA']);
});

function cicOverride(object $test, array $body)
{
    return $test->actingAs($test->preparer)->postJson('/api/cic/loan-overrides', $body);
}

it('creates an override on a released loan and audits it by display number', function () {
    cicOverride($this, ['loan_id' => $this->loan->id, 'contract_type' => '20', 'reason' => 'Paid by salary deduction'])
        ->assertCreated()
        ->assertJsonPath('data.loan.display_number', 'LN-CIC-0001')
        ->assertJsonPath('data.contract_type', '20')
        ->assertJsonPath('data.contract_type_label', 'Salary loan')
        ->assertJsonPath('data.record_type', null)
        ->assertJsonPath('data.product_mapping.contract_type', '12')
        ->assertJsonPath('data.reason', 'Paid by salary deduction')
        ->assertJsonPath('data.created_by.id', $this->preparer->id);

    $audit = AuditLog::query()->where('auditable_type', CicLoanOverride::class)->sole();
    expect($audit->action)->toBe('created')
        ->and($audit->new_values['loan'])->toBe('LN-CIC-0001')
        ->and($audit->new_values['contract_type'])->toBe('20 Salary loan')
        ->and($audit->description)->toBe('CIC override set on loan LN-CIC-0001: record type the product\'s; contract type 20 Salary loan; transaction type the product\'s. Reason: Paid by salary deduction')
        ->and($audit->branch_id)->toBe($this->loan->branch_id);
});

it('accepts every reportable status and refuses the others in words', function () {
    foreach (['ongoing', 'defaulted', 'completed', 'restructured'] as $status) {
        $loan = Loan::factory()->create(['status' => $status, 'loan_product_id' => $this->loan->loan_product_id]);
        cicOverride($this, ['loan_id' => $loan->id, 'transaction_type' => 'DL', 'reason' => 'Check'])->assertCreated();
    }

    $draft = Loan::factory()->create(['status' => 'draft', 'loan_product_id' => $this->loan->loan_product_id]);
    cicOverride($this, ['loan_id' => $draft->id, 'transaction_type' => 'DL', 'reason' => 'Check'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.loan_id.0', "Only loans that were released are reported to CIC; loan {$draft->displayNumber()} is still a draft.");
});

it('checks codes against the record type the loan reports as', function () {
    cicOverride($this, ['loan_id' => $this->loan->id, 'contract_type' => '48', 'reason' => 'x'])
        ->assertUnprocessable()->assertJsonPath('errors.contract_type.0', '"48" isn\'t one of CIC\'s contract type codes for a CI contract.');

    cicOverride($this, ['loan_id' => $this->loan->id, 'record_type' => 'CN', 'reason' => 'Single payment'])
        ->assertUnprocessable()->assertJsonPath('errors.contract_type.0', 'Choose a contract type for CN: the product\'s contract type 12 is a CI code.');

    cicOverride($this, ['loan_id' => $this->loan->id, 'transaction_type' => 'ZZ', 'reason' => 'x'])
        ->assertUnprocessable()->assertJsonPath('errors.transaction_type.0', '"ZZ" isn\'t one of CIC\'s transaction type codes for a CI contract.');

    cicOverride($this, ['loan_id' => $this->loan->id, 'reason' => 'Nothing'])
        ->assertUnprocessable()->assertJsonPath('errors.record_type.0', 'Set at least one of the record type, contract type or transaction type to override.');

    cicOverride($this, ['loan_id' => $this->loan->id, 'record_type' => 'CN', 'contract_type' => '48', 'reason' => 'Single payment'])
        ->assertCreated()->assertJsonPath('data.contract_type_label', 'Demand Loan – Case to Case');
});

it('asks for the record type when the loan\'s product has no mapping', function () {
    $loan = Loan::factory()->create(['status' => 'released']);

    cicOverride($this, ['loan_id' => $loan->id, 'contract_type' => '12', 'reason' => 'x'])
        ->assertUnprocessable()->assertJsonPath('errors.record_type.0', 'The loan\'s product has no CIC mapping yet, so choose the record type (CI or CN) too.');
});

it('needs a reason of up to 500 characters on every write', function () {
    cicOverride($this, ['loan_id' => $this->loan->id, 'contract_type' => '20'])
        ->assertUnprocessable()->assertJsonPath('errors.reason.0', 'Give a reason for the override.');
    cicOverride($this, ['loan_id' => $this->loan->id, 'contract_type' => '20', 'reason' => str_repeat('x', 501)])
        ->assertUnprocessable()->assertJsonValidationErrors('reason');

    $id = cicOverride($this, ['loan_id' => $this->loan->id, 'contract_type' => '20', 'reason' => 'ok'])->json('data.id');

    $this->actingAs($this->preparer)->putJson("/api/cic/loan-overrides/{$id}", ['contract_type' => '12'])
        ->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->actingAs($this->preparer)->deleteJson("/api/cic/loan-overrides/{$id}")
        ->assertUnprocessable()->assertJsonPath('errors.reason.0', 'Give a reason for removing the override.');
});

it('allows one override per loan', function () {
    cicOverride($this, ['loan_id' => $this->loan->id, 'contract_type' => '20', 'reason' => 'ok'])->assertCreated();

    cicOverride($this, ['loan_id' => $this->loan->id, 'contract_type' => '15', 'reason' => 'again'])
        ->assertConflict()->assertJsonPath('message', 'This loan already has a CIC override. Change or remove that one instead.');
});

it('changes and removes an override, each audited with its reason', function () {
    $id = cicOverride($this, ['loan_id' => $this->loan->id, 'contract_type' => '20', 'reason' => 'Salary deduction'])->json('data.id');

    $this->actingAs($this->preparer)->putJson("/api/cic/loan-overrides/{$id}", ['contract_type' => '16', 'transaction_type' => 'STT', 'reason' => 'Short term'])
        ->assertOk()
        ->assertJsonPath('data.contract_type', '16')
        ->assertJsonPath('data.transaction_type', 'STT')
        ->assertJsonPath('data.reason', 'Short term');

    $this->actingAs($this->preparer)->putJson("/api/cic/loan-overrides/{$id}", ['loan_id' => 5, 'contract_type' => '16', 'reason' => 'x'])
        ->assertUnprocessable()->assertJsonValidationErrors('loan_id');

    $updated = AuditLog::query()->where('auditable_type', CicLoanOverride::class)->where('action', 'updated')->sole();
    expect($updated->old_values['contract_type'])->toBe('20 Salary loan')
        ->and($updated->new_values['contract_type'])->toBe('16 Short Term Loan')
        ->and($updated->new_values['reason'])->toBe('Short term');

    $this->actingAs($this->preparer)->deleteJson("/api/cic/loan-overrides/{$id}", ['reason' => 'Product mapping is right'])->assertOk();

    $deleted = AuditLog::query()->where('auditable_type', CicLoanOverride::class)->where('action', 'deleted')->sole();
    expect(CicLoanOverride::query()->count())->toBe(0)
        ->and($deleted->auditable_id)->toBe($id)
        ->and($deleted->new_values['reason'])->toBe('Product mapping is right')
        ->and($deleted->description)->toContain('LN-CIC-0001');
});

it('lists overrides to viewers, paged, without member details', function () {
    cicOverride($this, ['loan_id' => $this->loan->id, 'contract_type' => '20', 'reason' => 'ok'])->assertCreated();

    $response = $this->actingAs(CicTestKeys::staff([CicAccess::VIEW]))->getJson('/api/cic/loan-overrides?loan_id='.$this->loan->id)
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.loan.display_number', 'LN-CIC-0001');

    expect(json_encode($response->json('data.0')))->not->toContain((string) $this->loan->borrower->last_name);
});

it('needs cic:prepare to write and cic:view to read', function () {
    $viewer = CicTestKeys::staff([CicAccess::VIEW, CicAccess::SETTINGS]);

    $this->actingAs($viewer)->postJson('/api/cic/loan-overrides', ['loan_id' => $this->loan->id, 'contract_type' => '20', 'reason' => 'x'])
        ->assertForbidden()->assertJsonPath('message', 'You don\'t have access to prepare CIC files.');
    $this->actingAs($this->preparer)->getJson('/api/cic/loan-overrides')->assertForbidden();
    $this->actingAs(CicTestKeys::staff([CicAccess::PREPARE], viewAll: false))
        ->postJson('/api/cic/loan-overrides', ['loan_id' => $this->loan->id, 'contract_type' => '20', 'reason' => 'x'])
        ->assertForbidden()->assertJsonPath('message', CicAccess::VIEW_ALL_REFUSAL);
});
