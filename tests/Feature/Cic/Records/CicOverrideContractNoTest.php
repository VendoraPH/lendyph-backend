<?php

use App\Models\CicCapture;
use App\Models\CicLoanOverride;
use App\Models\CicSubmission;
use App\Services\Cic\Capture\CicCaptureService;
use App\Services\Cic\CicAccess;
use App\Services\Cic\Records\CicRecord;
use App\Services\Cic\Records\CicRecordBuilder;
use Illuminate\Support\Carbon;
use Tests\Feature\Cic\Records\CicRecordsFixtures;
use Tests\TestCase;

uses(TestCase::class, CicRecordsFixtures::class);

/*
 * A loan override's own contract number (cic_loan_overrides.contract_no) is
 * the record key and CI7/CN7, ahead of the display number; once a submitted
 * file carries it, it can't change.
 */

beforeEach(function () {
    $this->cicOn();
    config(['cic.test_captures' => true]);
    $this->preparer = $this->cicUser([CicAccess::VIEW, CicAccess::PREPARE, 'branches:view_all']);
});

function cicOverrideRecord(int $loanId): CicRecord
{
    $set = app(CicRecordBuilder::class)->build(Carbon::parse('2026-11-30'));

    return collect([...$set->ofType('CI'), ...$set->ofType('CN')])->first(fn (CicRecord $r) => $r->loanId === $loanId);
}

it('reports an override\'s contract number as the record key and CI7/CN7, ahead of the display number', function () {
    $tooLong = $this->cicLoan(overrides: ['loan_account_number' => null, 'external_loan_no' => str_repeat('X', 45)]);
    $native = $this->cicLoan(overrides: ['loan_account_number' => 'LN-000501']);
    $lumpSum = $this->cicLoan(product: $this->cicProduct('CN'), periods: 1);

    expect(cicOverrideRecord($tooLong->id)->key)->toBe("loan-{$tooLong->id}");

    $this->actingAs($this->preparer)->postJson('/api/cic/loan-overrides', ['loan_id' => $tooLong->id, 'contract_no' => 'BC-OVR-0001', 'reason' => 'Imported Loan No. too long for CIC.'])->assertCreated();
    $this->actingAs($this->preparer)->postJson('/api/cic/loan-overrides', ['loan_id' => $native->id, 'contract_no' => 'BC-OVR-0002', 'reason' => 'Reported under the branch\'s number.'])->assertCreated();
    $this->actingAs($this->preparer)->postJson('/api/cic/loan-overrides', ['loan_id' => $lumpSum->id, 'contract_no' => 'BC-OVR-0003', 'reason' => 'Reported under the branch\'s number.'])->assertCreated();

    $long = cicOverrideRecord($tooLong->id);
    $ci = cicOverrideRecord($native->id);
    $cn = cicOverrideRecord($lumpSum->id);

    expect([$long->key, $long->values['CI7']])->toBe(['BC-OVR-0001', 'BC-OVR-0001'])
        ->and(collect($long->buildIssues)->pluck('code')->all())->not->toContain('CONTRACT_NO_TOO_LONG')
        ->and([$ci->key, $ci->values['CI7']])->toBe(['BC-OVR-0002', 'BC-OVR-0002'])
        ->and([$cn->type, $cn->key, $cn->values['CN7']])->toBe(['CN', 'BC-OVR-0003', 'BC-OVR-0003']);

    app(CicCaptureService::class)->capture(Carbon::parse('2026-11-30'), CicCapture::KIND_TEST, null);
    expect($tooLong->fresh()->cic_contract_no)->toBe('BC-OVR-0001')
        ->and($native->fresh()->cic_contract_no)->toBe('BC-OVR-0002');
});

it('keeps an override\'s contract number once a submitted file carries it', function () {
    $loan = $this->cicLoan(overrides: ['loan_account_number' => null, 'external_loan_no' => str_repeat('X', 45)]);
    $overrideId = $this->actingAs($this->preparer)->postJson('/api/cic/loan-overrides', ['loan_id' => $loan->id, 'contract_no' => 'BC-OVR-0009', 'reason' => 'Too long for CIC.'])
        ->assertCreated()->json('data.id');
    $capture = app(CicCaptureService::class)->capture(Carbon::parse('2026-11-30'), CicCapture::KIND_TEST, null);
    CicSubmission::factory()->submitted()->create(['capture_id' => $capture->id, 'reference_date' => '2026-11-30']);

    $this->actingAs($this->preparer)->putJson("/api/cic/loan-overrides/{$overrideId}", ['contract_no' => 'BC-OVR-0010', 'reason' => 'Renumbered.'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.contract_no.0', 'The contract number BC-OVR-0009 is in a file already submitted to CIC, so it can\'t change: CIC knows the contract by that number.');
    $this->actingAs($this->preparer)->deleteJson("/api/cic/loan-overrides/{$overrideId}", ['reason' => 'Not needed.'])
        ->assertUnprocessable()->assertJsonValidationErrors('contract_no');

    expect(CicLoanOverride::query()->findOrFail($overrideId)->contract_no)->toBe('BC-OVR-0009')
        ->and(cicOverrideRecord($loan->id)->key)->toBe('BC-OVR-0009');
});
