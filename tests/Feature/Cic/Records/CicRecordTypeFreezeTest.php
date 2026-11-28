<?php

use App\Exceptions\CicRecordIsFrozenException;
use App\Models\AmortizationSchedule;
use App\Models\CicCapture;
use App\Models\CicLoanOverride;
use App\Services\Cic\Capture\CicCaptureService;
use App\Services\Cic\Records\CicRecord;
use App\Services\Cic\Records\CicRecordBuilder;
use Illuminate\Support\Carbon;
use Tests\Feature\Cic\Records\CicRecordsFixtures;
use Tests\TestCase;

uses(TestCase::class, CicRecordsFixtures::class);

/*
 * Money review M-M5: a loan's record type is frozen at its first capture
 * (loans.cic_record_type), so an extension that adds a period can't turn a CN
 * contract into CI. A loan override's record type still wins.
 */

beforeEach(function () {
    $this->cicOn();
    config(['cic.test_captures' => true]);
});

it('freezes the record type at the first capture, so an extra period never turns CN into CI', function () {
    $loan = $this->cicLoan(product: $this->cicProduct('CN'), periods: 1);
    $typeOf = fn () => collect([...app(CicRecordBuilder::class)->build(Carbon::parse('2026-11-30'))->ofType('CI'), ...app(CicRecordBuilder::class)->build(Carbon::parse('2026-11-30'))->ofType('CN')])
        ->first(fn (CicRecord $r) => $r->loanId === $loan->id)?->type;

    expect($loan->cic_record_type)->toBeNull();
    app(CicCaptureService::class)->capture(Carbon::parse('2026-11-30'), CicCapture::KIND_TEST, null);
    expect($loan->fresh()->cic_record_type)->toBe('CN');

    // An extension adds a period: worked out now, the loan would be CI.
    AmortizationSchedule::factory()->create(['loan_id' => $loan->id, 'period_number' => 2, 'due_date' => '2026-12-15', 'principal_due' => 10000, 'interest_due' => 300, 'total_due' => 10300, 'remaining_balance' => 0, 'status' => 'pending']);

    expect($typeOf())->toBe('CN');

    CicLoanOverride::factory()->create(['loan_id' => $loan->id, 'record_type' => 'CI', 'contract_type' => '12', 'transaction_type' => 'NA', 'reason' => 'Reported as instalments.']);
    expect($typeOf())->toBe('CI')
        ->and($loan->fresh()->cic_record_type)->toBe('CN');
});

it('never changes a frozen record type', function () {
    $loan = $this->cicLoan();
    app(CicCaptureService::class)->capture(Carbon::parse('2026-11-30'), CicCapture::KIND_TEST, null);
    $loan = $loan->fresh();

    expect($loan->cic_record_type)->toBe('CI')
        ->and(fn () => $loan->forceFill(['cic_record_type' => 'CN'])->save())->toThrow(CicRecordIsFrozenException::class, 'record type never changes');
});
