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

it('moves the frozen figures to the other layout when the record type changes', function () {
    $loan = $this->cicLoan();
    $monthEnd = cicReread(CicCapture::KIND_MONTH_END);
    $before = cicRereadRecord($monthEnd, $loan->id)->values;

    CicLoanOverride::factory()->create(['loan_id' => $loan->id, 'record_type' => 'CN', 'contract_type' => '48', 'transaction_type' => 'DL', 'reason' => 'Demand loan.']);
    $after = cicRereadRecord(cicReread(CicCapture::KIND_CORRECTION, $monthEnd), $loan->id);

    expect($after->record_type)->toBe('CN')
        ->and($after->values)->toMatchArray([
            'CN1' => 'CN', 'CN7' => $before['CI7'], 'CN8' => '48', 'CN21' => 'DL',
            'CN9' => $before['CI9'], 'CN13' => $before['CI13'], 'CN15' => $before['CI15'], 'CN20' => $before['CI20'],
            'CN23' => $before['CI32'], 'CN24' => $before['CI34'],
        ])
        ->and($after->values)->not->toHaveKey('CN32')->not->toHaveKey('CN22');
});
