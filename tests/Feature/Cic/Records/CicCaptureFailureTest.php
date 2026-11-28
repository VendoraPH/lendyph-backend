<?php

use App\Models\CicCapture;
use App\Models\CicCaptureRecord;
use App\Services\Cic\Capture\CicCaptureService;
use App\Services\Cic\CicValidator;
use App\Services\Cic\Records\CicRecord;
use App\Services\Cic\Records\CicRecordBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Cic\Records\CicRecordsFixtures;
use Tests\TestCase;

uses(TestCase::class, CicRecordsFixtures::class);

/*
 * Security review S-M1 and S-L8: a duplicate contract number never breaks a
 * capture; a capture that fails anyway logs only a cleaned exception (class,
 * SQLSTATE, capture id), never SQL holding members' values, and keeps none of
 * its partial records.
 */

beforeEach(function () {
    $this->cicOn();
    config(['cic.test_captures' => true]);
});

it('keys a repeated contract number by the loan id with an error, keeping it for the loan whose number it is', function () {
    $native = $this->cicLoan(overrides: ['loan_account_number' => 'LN-000777']);
    $imported = $this->cicLoan(overrides: ['loan_account_number' => null, 'external_loan_no' => 'LN-000777']);

    $set = app(CicRecordBuilder::class)->build(Carbon::parse('2026-11-30'));
    $byLoan = collect($set->ofType('CI'))->keyBy(fn (CicRecord $r) => $r->loanId);

    expect($byLoan[$native->id]->key)->toBe('LN-000777')
        ->and($byLoan[$imported->id]->key)->toBe("loan-{$imported->id}")
        ->and($byLoan[$imported->id]->values['CI7'])->toBe('LN-000777')
        ->and(collect($byLoan[$imported->id]->buildIssues)->where('code', 'DUPLICATE_CONTRACT')->sole())->toMatchArray(['field_id' => 'CI7', 'severity' => 'error']);

    $capture = app(CicCaptureService::class)->capture(Carbon::parse('2026-11-30'), CicCapture::KIND_TEST, null);
    $duplicates = collect(app(CicValidator::class)->issues($capture))->where('code', 'DUPLICATE_CONTRACT');

    expect($capture->status)->toBe('complete')
        ->and($capture->contract_count)->toBe(2)
        ->and($duplicates->pluck('record_key')->all())->toBe(["loan-{$imported->id}"])
        ->and($native->fresh()->cic_contract_no)->toBe('LN-000777')
        ->and($imported->fresh()->cic_contract_no)->toBeNull();
});

it('logs a failed capture as a cleaned exception, reports nothing, and keeps none of its records', function () {
    Exceptions::fake();
    Log::spy();
    $this->cicLoan();
    $broken = $this->cicLoan();
    DB::table('borrowers')->where('id', $broken->borrower_id)->update(['borrower_code' => '']);

    $capture = app(CicCaptureService::class)->capture(Carbon::parse('2026-11-30'), CicCapture::KIND_TEST, null);

    expect($capture->status)->toBe('failed')
        ->and(CicCaptureRecord::query()->where('capture_id', $capture->id)->count())->toBe(0);

    Exceptions::assertNothingReported();
    Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context) use ($capture) {
        return $message === 'CIC capture failed.'
            && array_keys($context) === ['capture_id', 'exception', 'sqlstate']
            && $context['capture_id'] === $capture->id
            && ! str_contains(json_encode($context), 'Maria');
    });
});

it('deletes the records a capture wrote before it failed', function () {
    Log::spy();
    $this->cicLoan();
    $written = 0;
    CicCapture::saving(function (CicCapture $capture) use (&$written) {
        if ($capture->status === CicCapture::STATUS_COMPLETE) {
            $written = CicCaptureRecord::query()->where('capture_id', $capture->id)->count();

            throw new RuntimeException('The last step failed.');
        }
    });

    $capture = app(CicCaptureService::class)->capture(Carbon::parse('2026-11-30'), CicCapture::KIND_TEST, null);

    expect($written)->toBe(2)
        ->and($capture->status)->toBe('failed')
        ->and(CicCaptureRecord::query()->where('capture_id', $capture->id)->count())->toBe(0);
    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $context['exception'] === RuntimeException::class && $context['sqlstate'] === null);
});
