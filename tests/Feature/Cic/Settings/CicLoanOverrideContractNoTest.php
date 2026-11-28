<?php

use App\Models\AuditLog;
use App\Models\CicCapture;
use App\Models\CicCaptureRecord;
use App\Models\CicLoanOverride;
use App\Models\CicProductMapping;
use App\Models\CicPublicKey;
use App\Models\CicSubmission;
use App\Models\Loan;
use App\Services\Cic\CicAccess;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cic\Settings\CicTestKeys;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Review M-H1 (override side): an override's own CIC contract number, for a
 * loan whose display number is too long or collides. Up to 38 letters, digits
 * and dashes; unique among other loans' contract and display numbers and
 * other overrides; kept once a submitted file carries it; audited.
 *
 * Review S-L7: key and override ids are looked up only after the caller is
 * authorized, so a caller without access can't tell whether an id exists.
 */

beforeEach(function () {
    config(['cic.enabled' => true]);
    $this->preparer = CicTestKeys::staff([CicAccess::PREPARE, CicAccess::VIEW]);
    $this->loan = Loan::factory()->create(['status' => 'released', 'loan_account_number' => 'LN-CIC-0001']);
    CicProductMapping::factory()->create(['loan_product_id' => $this->loan->loan_product_id, 'record_type' => 'CI', 'contract_type' => '12', 'transaction_type' => 'NA']);
});

/** A file that went to CIC (submitted) whose capture holds a CI contract keyed $contractNo. */
function cicFiledContract(string $contractNo): CicSubmission
{
    $capture = CicCapture::factory()->create(['kind' => CicCapture::KIND_MONTH_END, 'reference_date' => '2026-10-31']);
    $values = ['CI1' => 'CI', 'CI5' => $contractNo];
    CicCaptureRecord::factory()->for($capture, 'capture')->create([
        'record_type' => 'CI', 'record_key' => $contractNo, 'values' => $values, 'checksum' => CicCaptureRecord::checksumOf($values),
    ]);
    $capture->forceFill(['status' => CicCapture::STATUS_COMPLETE, 'contract_count' => 1, 'checksum' => $capture->computeChecksum(), 'finished_at' => now()])->save();

    return CicSubmission::factory()->submitted()->create(['capture_id' => $capture->id, 'reference_date' => '2026-10-31']);
}

it('sets an override contract number on its own, audited and shown', function () {
    $response = $this->actingAs($this->preparer)->postJson('/api/cic/loan-overrides', [
        'loan_id' => $this->loan->id, 'contract_no' => ' LN-CIC-0001-A ', 'reason' => 'Imported number is too long',
    ])->assertCreated()
        ->assertJsonPath('data.contract_no', 'LN-CIC-0001-A')
        ->assertJsonPath('data.contract_no_reported', false)
        ->assertJsonPath('data.contract_type', null);

    expect(CicLoanOverride::query()->sole()->contract_no)->toBe('LN-CIC-0001-A');

    $audit = AuditLog::query()->where('auditable_type', CicLoanOverride::class)->where('auditable_id', $response->json('data.id'))->sole();
    expect($audit->new_values['contract_no'])->toBe('LN-CIC-0001-A')
        ->and($audit->description)->toContain('contract number LN-CIC-0001-A');
});

it('refuses a contract number that is too long or holds other characters, in words', function () {
    foreach ([str_repeat('A', 39) => 'The contract number takes up to 38 characters, CIC\'s limit.', 'LN 0001' => 'The contract number takes letters, digits and dashes only.', 'LN_0001' => 'The contract number takes letters, digits and dashes only.', 'LN|0001' => 'The contract number takes letters, digits and dashes only.'] as $number => $message) {
        $this->actingAs($this->preparer)->postJson('/api/cic/loan-overrides', ['loan_id' => $this->loan->id, 'contract_no' => $number, 'reason' => 'x'])
            ->assertUnprocessable()->assertJsonPath('errors.contract_no.0', $message);
    }

    $this->actingAs($this->preparer)->postJson('/api/cic/loan-overrides', ['loan_id' => $this->loan->id, 'contract_no' => str_repeat('A', 38), 'reason' => 'x'])
        ->assertCreated();
});

it('refuses a contract number another loan or override already uses', function () {
    $other = Loan::factory()->create(['status' => 'released', 'loan_product_id' => $this->loan->loan_product_id, 'loan_account_number' => 'LN-CIC-0002', 'external_loan_no' => 'BC-77']);
    $third = Loan::factory()->create(['status' => 'released', 'loan_product_id' => $this->loan->loan_product_id]);
    $third->forceFill(['cic_contract_no' => 'CIC-TAKEN'])->save();
    $fourth = Loan::factory()->create(['status' => 'released', 'loan_product_id' => $this->loan->loan_product_id]);
    $this->actingAs($this->preparer)->postJson('/api/cic/loan-overrides', ['loan_id' => $fourth->id, 'contract_no' => 'OVR-1', 'reason' => 'x'])->assertCreated();

    foreach (['LN-CIC-0002', 'BC-77', 'CIC-TAKEN', 'OVR-1'] as $number) {
        $this->actingAs($this->preparer)->postJson('/api/cic/loan-overrides', ['loan_id' => $this->loan->id, 'contract_no' => $number, 'reason' => 'x'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.contract_no.0', "The contract number {$number} already names another loan's contract. Choose one no other loan uses.");
    }

    expect(CicLoanOverride::query()->where('loan_id', $this->loan->id)->exists())->toBeFalse()
        ->and($other->fresh()->cic_contract_no)->toBeNull();
});

it('refuses a contract number other than the one the loan already reports by', function () {
    $this->loan->forceFill(['cic_contract_no' => 'LN-CIC-0001'])->save();

    $this->actingAs($this->preparer)->postJson('/api/cic/loan-overrides', ['loan_id' => $this->loan->id, 'contract_no' => 'LN-NEW-1', 'reason' => 'x'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.contract_no.0', 'Loan LN-CIC-0001 already reports to CIC as LN-CIC-0001, and a contract number never changes once set.');
    $this->actingAs($this->preparer)->postJson('/api/cic/loan-overrides', ['loan_id' => $this->loan->id, 'contract_no' => 'LN-CIC-0001', 'reason' => 'Same number'])
        ->assertCreated();
});

it('keeps a contract number once a submitted file carries it: no change, no removal, codes still change', function () {
    $id = $this->actingAs($this->preparer)->postJson('/api/cic/loan-overrides', [
        'loan_id' => $this->loan->id, 'contract_no' => 'LN-CIC-0001-A', 'reason' => 'Collides',
    ])->assertCreated()->json('data.id');

    // Before any file carries it, it may still change.
    $this->actingAs($this->preparer)->putJson("/api/cic/loan-overrides/{$id}", ['contract_no' => 'LN-CIC-0001-B', 'reason' => 'Typo'])
        ->assertOk()->assertJsonPath('data.contract_no', 'LN-CIC-0001-B');

    cicFiledContract('LN-CIC-0001-B');

    $this->actingAs($this->preparer)->putJson("/api/cic/loan-overrides/{$id}", ['contract_no' => 'LN-CIC-0001-C', 'reason' => 'Again'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.contract_no.0', 'The contract number LN-CIC-0001-B is in a file already submitted to CIC, so it can\'t change: CIC knows the contract by that number.');
    $this->actingAs($this->preparer)->putJson("/api/cic/loan-overrides/{$id}", ['contract_no' => null, 'contract_type' => '20', 'reason' => 'Clear it'])
        ->assertUnprocessable()->assertJsonValidationErrors('contract_no');
    $this->actingAs($this->preparer)->deleteJson("/api/cic/loan-overrides/{$id}", ['reason' => 'Remove'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.contract_no.0', 'This override\'s contract number LN-CIC-0001-B is in a file already submitted to CIC, so the override stays: CIC knows the contract by that number. Change its codes instead.');

    // Leaving it out keeps it; the codes still change.
    $this->actingAs($this->preparer)->putJson("/api/cic/loan-overrides/{$id}", ['contract_type' => '20', 'reason' => 'Salary deduction'])
        ->assertOk()
        ->assertJsonPath('data.contract_no', 'LN-CIC-0001-B')
        ->assertJsonPath('data.contract_type', '20')
        ->assertJsonPath('data.contract_no_reported', true);

    $this->actingAs($this->preparer)->getJson('/api/cic/loan-overrides')
        ->assertOk()->assertJsonPath('data.0.contract_no', 'LN-CIC-0001-B')->assertJsonPath('data.0.contract_no_reported', true);

    expect(CicLoanOverride::query()->sole()->contract_no)->toBe('LN-CIC-0001-B');
});

it('counts only files that went to CIC: a generated or encrypted file doesn\'t freeze the number', function () {
    $id = $this->actingAs($this->preparer)->postJson('/api/cic/loan-overrides', [
        'loan_id' => $this->loan->id, 'contract_no' => 'LN-CIC-0001-A', 'reason' => 'Collides',
    ])->assertCreated()->json('data.id');
    $filed = cicFiledContract('LN-CIC-0001-A');
    DB::table('cic_submissions')->where('id', $filed->id)->update(['status' => CicSubmission::STATUS_ENCRYPTED]);

    $this->actingAs($this->preparer)->putJson("/api/cic/loan-overrides/{$id}", ['contract_no' => null, 'contract_type' => '20', 'reason' => 'Not needed'])
        ->assertOk()->assertJsonPath('data.contract_no', null);
});

it('authorizes before looking up an override or key id (no probing)', function () {
    $id = $this->actingAs($this->preparer)->postJson('/api/cic/loan-overrides', ['loan_id' => $this->loan->id, 'contract_type' => '20', 'reason' => 'x'])->json('data.id');
    $viewer = CicTestKeys::staff([CicAccess::VIEW]);

    foreach ([$id, 999999] as $overrideId) {
        $this->actingAs($viewer)->putJson("/api/cic/loan-overrides/{$overrideId}", ['contract_type' => '20', 'reason' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->deleteJson("/api/cic/loan-overrides/{$overrideId}", ['reason' => 'x'])->assertForbidden();
    }

    $this->actingAs($this->preparer)->putJson('/api/cic/loan-overrides/999999', ['contract_type' => '20', 'reason' => 'x'])
        ->assertNotFound()->assertJsonPath('message', 'There is no such CIC override.');
    $this->actingAs($this->preparer)->deleteJson('/api/cic/loan-overrides/999999', ['reason' => 'x'])
        ->assertNotFound()->assertJsonPath('message', 'There is no such CIC override.');

    $key = CicPublicKey::factory()->create();

    foreach ([$key->id, 999999] as $keyId) {
        $this->actingAs($viewer)->postJson("/api/cic/public-keys/{$keyId}/verify", ['fingerprint' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/cic/public-keys/{$keyId}/retire", ['reason' => 'x'])->assertForbidden();
    }

    $admin = CicTestKeys::staff([CicAccess::SETTINGS]);
    $this->actingAs($admin)->postJson('/api/cic/public-keys/999999/verify', ['fingerprint' => 'x'])
        ->assertNotFound()->assertJsonPath('message', 'There is no such CIC public key.');
    $this->actingAs($admin)->postJson('/api/cic/public-keys/999999/retire', ['reason' => 'x'])
        ->assertNotFound()->assertJsonPath('message', 'There is no such CIC public key.');
    $this->actingAs($admin)->postJson("/api/cic/public-keys/{$key->id}/retire", ['reason' => 'Not CIC\'s'])
        ->assertOk()->assertJsonPath('data.status', 'retired');
});
