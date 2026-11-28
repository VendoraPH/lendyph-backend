<?php

use App\Models\CicCapture;
use App\Models\CicCaptureRecord;
use App\Models\CicLoanOverride;
use App\Models\CicProductMapping;
use App\Models\LoanProduct;
use App\Models\Repayment;
use App\Services\Cic\Capture\CicCaptureService;
use App\Services\Cic\CicValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cic\Records\CicRecordsFixtures;
use Tests\TestCase;

uses(TestCase::class, CicRecordsFixtures::class);

/*
 * Money review M-H1, records side: a correction capture of a month end
 * re-reads each contract's classification and key from today (record type,
 * CI7/CN7, CI8/CN8, CI22/CN21, CI23) and keeps every money and date field
 * from the month end, so a month whose file couldn't be made can be filed.
 */

beforeEach(function () {
    $this->cicOn();
});

function cicReread(string $kind, ?CicCapture $corrects = null): CicCapture
{
    return app(CicCaptureService::class)->capture(Carbon::parse('2026-11-30'), $kind, null, $corrects);
}

function cicRereadRecord(CicCapture $capture, int $loanId): CicCaptureRecord
{
    return CicCaptureRecord::query()->where('capture_id', $capture->id)->where('loan_id', $loanId)->sole();
}

it('re-reads an unmapped product\'s codes and keeps the month-end figures', function () {
    $product = LoanProduct::factory()->create(['name' => 'Emergency Loan']);
    $loan = $this->cicLoan(product: $product);
    $monthEnd = cicReread(CicCapture::KIND_MONTH_END);
    $before = cicRereadRecord($monthEnd, $loan->id);

    expect($before->values)->not->toHaveKey('CI8')
        ->and(collect($before->build_issues)->pluck('code')->all())->toContain('UNMAPPED_PRODUCT');

    // Today: the product is mapped, and a payment has moved the balance.
    CicProductMapping::factory()->create(['loan_product_id' => $product->id, 'record_type' => 'CI', 'contract_type' => '18', 'transaction_type' => 'NA', 'purpose_code' => '10']);
    Repayment::factory()->create(['loan_id' => $loan->id, 'payment_date' => '2026-11-30', 'amount_paid' => 5000, 'status' => 'posted']);
    $loan->amortizationSchedules()->where('period_number', 1)->update(['principal_paid' => 4700, 'interest_paid' => 300, 'status' => 'partial']);

    $correction = cicReread(CicCapture::KIND_CORRECTION, $monthEnd);
    $after = cicRereadRecord($correction, $loan->id);
    $frozen = collect($before->values)->except(['CI8', 'CI22', 'CI23'])->all();

    expect($correction->status)->toBe('complete')
        ->and($after->values)->toMatchArray(['CI8' => '18', 'CI22' => 'NA', 'CI23' => '10'])
        ->and(collect($after->values)->except(['CI8', 'CI22', 'CI23'])->all())->toEqual($frozen)
        ->and(collect($after->build_issues ?? [])->pluck('code')->all())->not->toContain('UNMAPPED_PRODUCT')
        ->and(collect(app(CicValidator::class)->issues($correction))->where('severity', 'error')->where('record_key', $loan->loan_account_number)->all())->toBe([]);
});

it('re-reads a contract number CIC can\'t take once the loan has one it can', function () {
    $loan = $this->cicLoan(overrides: ['loan_account_number' => null, 'external_loan_no' => str_repeat('X', 45)]);
    $monthEnd = cicReread(CicCapture::KIND_MONTH_END);

    expect(cicRereadRecord($monthEnd, $loan->id)->record_key)->toBe("loan-{$loan->id}");

    DB::table('loans')->where('id', $loan->id)->update(['external_loan_no' => 'BC-777001']);
    $after = cicRereadRecord(cicReread(CicCapture::KIND_CORRECTION, $monthEnd), $loan->id);

    expect($after->record_key)->toBe('BC-777001')
        ->and($after->values['CI7'])->toBe('BC-777001')
        ->and(collect($after->build_issues ?? [])->pluck('code')->all())->not->toContain('CONTRACT_NO_TOO_LONG')
        ->and($loan->fresh()->cic_contract_no)->toBe('BC-777001');
});

it('keeps the month end\'s record type and figures when the record type changed, with a warning', function () {
    $loan = $this->cicLoan();
    $monthEnd = cicReread(CicCapture::KIND_MONTH_END);
    $before = cicRereadRecord($monthEnd, $loan->id);

    CicLoanOverride::factory()->create(['loan_id' => $loan->id, 'record_type' => 'CN', 'contract_type' => '48', 'transaction_type' => 'DL', 'reason' => 'Demand loan.']);
    $after = cicRereadRecord(cicReread(CicCapture::KIND_CORRECTION, $monthEnd), $loan->id);

    expect($after->record_type)->toBe('CI')
        ->and($after->values)->toEqual($before->values)
        ->and(collect($after->build_issues)->where('code', 'CORRECTION_RECORD_TYPE_CHANGED')->sole())->toMatchArray(['field_id' => 'CI1', 'severity' => 'warning'])
        ->and(collect($after->build_issues)->sole()['message'])->toContain('now classified as a CN contract');
});

it('re-reads the branch code, which isn\'t money', function () {
    $branch = $this->cicBranch('TOOLONG');
    $member = $this->cicMember(['branch_id' => $branch->id]);
    $loan = $this->cicLoan($member);
    $monthEnd = cicReread(CicCapture::KIND_MONTH_END);

    expect(cicRereadRecord($monthEnd, $loan->id)->values)->not->toHaveKey('CI3');

    DB::table('branches')->where('id', $branch->id)->update(['code' => 'NAGA']);
    expect(cicRereadRecord(cicReread(CicCapture::KIND_CORRECTION, $monthEnd), $loan->id)->values['CI3'])->toBe('NAGA');
});

it('checks the loans the month end left out again, so a held-back closure never disappears', function () {
    $loan = $this->cicLoan();
    $previous = CicCapture::factory()->monthEnd('2026-10-31')->create();
    $values = ['CI1' => 'CI', 'CI7' => $loan->loan_account_number, 'CI9' => 'AC'];
    CicCaptureRecord::factory()->for($previous, 'capture')->create(['record_type' => 'CI', 'record_key' => $loan->loan_account_number, 'loan_id' => $loan->id, 'values' => $values, 'checksum' => CicCaptureRecord::checksumOf($values)]);
    $previous->forceFill(['status' => CicCapture::STATUS_COMPLETE, 'checksum' => $previous->computeChecksum(), 'finished_at' => now()])->save();
    DB::table('loans')->where('id', $loan->id)->update(['status' => 'completed']);

    $monthEnd = cicReread(CicCapture::KIND_MONTH_END);
    expect($monthEnd->left_out)->toHaveCount(1)
        ->and($monthEnd->left_out[0])->toMatchArray(['code' => 'NO_CLOSING_EVENT', 'severity' => 'error', 'loan_id' => $loan->id]);

    // Not fixed yet: the correction holds the same error.
    $still = cicReread(CicCapture::KIND_CORRECTION, $monthEnd);
    expect(collect(app(CicValidator::class)->issues($still))->where('code', 'NO_CLOSING_EVENT')->where('severity', 'error')->count())->toBe(1);

    // Fixed: the closing payment is recorded, and the closure is reported.
    Repayment::factory()->create(['loan_id' => $loan->id, 'payment_date' => '2026-10-30', 'amount_paid' => 30900, 'status' => 'posted']);
    $fixed = cicReread(CicCapture::KIND_CORRECTION, $monthEnd);
    $closure = cicRereadRecord($fixed, $loan->id);

    expect($fixed->left_out)->toBeNull()
        ->and($closure->values['CI9'])->toBe('CA')
        ->and($closure->values['CI16'])->toBe('30102026');
});

it('leaves a held-back loan out again when its closure is recorded after the month end', function () {
    $loan = $this->cicLoan();
    $previous = CicCapture::factory()->monthEnd('2026-10-31')->create();
    $values = ['CI1' => 'CI', 'CI7' => $loan->loan_account_number, 'CI9' => 'AC'];
    CicCaptureRecord::factory()->for($previous, 'capture')->create(['record_type' => 'CI', 'record_key' => $loan->loan_account_number, 'loan_id' => $loan->id, 'values' => $values, 'checksum' => CicCaptureRecord::checksumOf($values)]);
    $previous->forceFill(['status' => CicCapture::STATUS_COMPLETE, 'checksum' => $previous->computeChecksum(), 'finished_at' => now()])->save();
    DB::table('loans')->where('id', $loan->id)->update(['status' => 'completed']);
    $monthEnd = cicReread(CicCapture::KIND_MONTH_END);

    // Fixed after the month end: the closing payment is dated December.
    Repayment::factory()->create(['loan_id' => $loan->id, 'payment_date' => '2026-12-02', 'amount_paid' => 30900, 'status' => 'posted']);
    $correction = cicReread(CicCapture::KIND_CORRECTION, $monthEnd);

    expect(CicCaptureRecord::query()->where('capture_id', $correction->id)->where('loan_id', $loan->id)->exists())->toBeFalse()
        ->and($correction->left_out)->toHaveCount(1)
        ->and($correction->left_out[0])->toMatchArray(['code' => 'NO_CLOSING_EVENT', 'severity' => 'error', 'loan_id' => $loan->id])
        ->and($correction->left_out[0]['message'])->toContain('after this file\'s reference date');
});
