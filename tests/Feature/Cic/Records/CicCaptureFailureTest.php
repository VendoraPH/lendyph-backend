<?php

use App\Models\CicCapture;
use App\Models\CicCaptureRecord;
use App\Services\Cic\Capture\CicCaptureService;
use App\Services\Cic\CicAccess;
use App\Services\Cic\CicValidator;
use App\Services\Cic\Records\CicFormat;
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

it('treats a number another loan froze as taken, even when that loan isn\'t in the file', function () {
    $closed = $this->cicLoan(overrides: ['loan_account_number' => 'LN-000045', 'status' => 'completed']);
    DB::table('loans')->where('id', $closed->id)->update(['cic_contract_no' => 'LN-000045']);
    $imported = $this->cicLoan(overrides: ['loan_account_number' => null, 'external_loan_no' => 'LN-000045']);

    $set = app(CicRecordBuilder::class)->build(Carbon::parse('2026-11-30'));
    $byLoan = collect($set->ofType('CI'))->keyBy(fn (CicRecord $r) => $r->loanId);

    expect($byLoan)->not->toHaveKey($closed->id)
        ->and($byLoan[$imported->id]->key)->toBe("loan-{$imported->id}")
        ->and(collect($byLoan[$imported->id]->buildIssues)->pluck('code')->all())->toContain('DUPLICATE_CONTRACT');
});

it('compares contract numbers case-folded, and reserves the loan- and digits form', function () {
    $native = $this->cicLoan(overrides: ['loan_account_number' => 'LN-000004']);
    $lower = $this->cicLoan(overrides: ['loan_account_number' => null, 'external_loan_no' => 'ln-000004']);
    $shaped = $this->cicLoan(overrides: ['loan_account_number' => null, 'external_loan_no' => 'LOAN-'.($native->id)]);

    $capture = app(CicCaptureService::class)->capture(Carbon::parse('2026-11-30'), CicCapture::KIND_TEST, null);
    $keys = CicCaptureRecord::query()->where('capture_id', $capture->id)->where('record_type', 'CI')->pluck('record_key', 'loan_id');

    expect($capture->status)->toBe('complete')
        ->and($keys[$native->id])->toBe('LN-000004')
        ->and($keys[$lower->id])->toBe("loan-{$lower->id}")
        ->and($keys[$shaped->id])->toBe("loan-{$shaped->id}")
        ->and($native->fresh()->cic_contract_no)->toBe('LN-000004')
        ->and($lower->fresh()->cic_contract_no)->toBeNull();

    $preparer = $this->cicUser([CicAccess::PREPARE, 'branches:view_all']);
    $this->actingAs($preparer)->postJson('/api/cic/loan-overrides', ['loan_id' => $lower->id, 'contract_no' => 'LOAN-7', 'reason' => 'x'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.contract_no.0', 'The contract number can\'t be "loan-" and digits: CIC Reporting keeps that form for loans with no number of their own.');
});

it('folds accents as well as capitals when it compares contract numbers, and keeps other characters', function () {
    expect(CicFormat::fold('PEÑA-01'))->toBe('pena-01')
        ->and(CicFormat::fold('Ｃａｆé'))->toBe('cafe')
        ->and(CicFormat::fold('BC/07-2024'))->toBe('bc/07-2024')
        ->and(CicFormat::fold("LN-\xC3"))->toBe(mb_strtolower("LN-\xC3"))
        ->and(class_exists(Normalizer::class))->toBeTrue();

    $frozen = $this->cicLoan(overrides: ['loan_account_number' => 'PENA-01']);
    DB::table('loans')->where('id', $frozen->id)->update(['cic_contract_no' => 'PENA-01']);
    $accented = $this->cicLoan(overrides: ['loan_account_number' => null, 'external_loan_no' => 'PEÑA-01']);
    $slashed = $this->cicLoan(overrides: ['loan_account_number' => null, 'external_loan_no' => 'BC/07-2024']);

    $capture = app(CicCaptureService::class)->capture(Carbon::parse('2026-11-30'), CicCapture::KIND_TEST, null);
    $keys = CicCaptureRecord::query()->where('capture_id', $capture->id)->where('record_type', 'CI')->pluck('record_key', 'loan_id');

    expect($capture->status)->toBe('complete')
        ->and($keys[$frozen->id])->toBe('PENA-01')
        ->and($keys[$accented->id])->toBe("loan-{$accented->id}")
        ->and($keys[$slashed->id])->toBe('BC/07-2024')
        ->and($slashed->fresh()->cic_contract_no)->toBe('BC/07-2024');
});
