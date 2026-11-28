<?php

use App\Exceptions\CicRecordIsFrozenException;
use App\Models\Borrower;
use App\Models\CicCapture;
use App\Models\CicCaptureRecord;
use App\Models\CicCodeMapping;
use App\Models\CicLoanOverride;
use App\Models\CicProductMapping;
use App\Models\CicPublicKey;
use App\Models\CicSetting;
use App\Models\CicSubject;
use App\Models\CicSubmission;
use App\Models\CoMaker;
use App\Models\Loan;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

/*
 * The CIC tables' own guards: a complete capture and its records never change;
 * one complete month-end capture per date; a subject's number never changes;
 * at most one active public key; a submission moves forward only; a loan's
 * contract number is set once and never mass-assigned. Each holds on the
 * model, on bulk writes, and (one active key, one month-end) in the database.
 */

it('makes every model from its factory', function () {
    expect(CicSetting::factory()->create()->format_version)->toBe('1.0')
        ->and(CicPublicKey::factory()->create()->status)->toBe('pending')
        ->and(CicProductMapping::factory()->create()->loanProduct)->not->toBeNull()
        ->and(CicLoanOverride::factory()->create()->loan)->not->toBeNull()
        ->and(CicCodeMapping::factory()->create()->domain)->toBe('id_kind')
        ->and(CicSubject::factory()->create()->subject_no)->toStartWith('BRW-')
        ->and(CicSubject::factory()->coMaker()->create()->subject_no)->toStartWith('CMK-')
        ->and(CicCapture::factory()->create()->status)->toBe('capturing')
        ->and(CicCaptureRecord::factory()->create()->checksum)->toHaveLength(64)
        ->and(CicSubmission::factory()->submitted()->create()->status)->toBe('submitted');

    $capture = CicCapture::factory()->completeWithRecords(2, 3)->create()->fresh();

    expect($capture->status)->toBe('complete')
        ->and($capture->records()->count())->toBe(5)
        ->and($capture->checksum)->toBe($capture->computeChecksum());
});

it('keeps the format version out of mass assignment and makes one settings row', function () {
    $setting = CicSetting::current();
    $setting->fill(['format_version' => '2.0', 'provider_code' => 'ABCD1234'])->save();

    expect($setting->fresh()->format_version)->toBe('1.0')
        ->and($setting->fresh()->provider_code)->toBe('ABCD1234')
        ->and(CicSetting::current()->id)->toBe($setting->id)
        ->and(CicSetting::query()->count())->toBe(1);
});

it('checksums a record\'s values in field order, whatever order they come in', function () {
    expect(CicCaptureRecord::checksumOf(['ID10' => 'b', 'ID2' => 'a', 'ID1' => 'ID']))
        ->toBe(CicCaptureRecord::checksumOf(['ID1' => 'ID', 'ID2' => 'a', 'ID10' => 'b']))
        ->and(CicCaptureRecord::checksumOf(['ID1' => 'ID', 'ID2' => 'a']))
        ->not->toBe(CicCaptureRecord::checksumOf(['ID1' => 'ID', 'ID2' => 'b']));

    $record = CicCaptureRecord::factory()->create();

    expect($record->fresh()->checksum)->toBe(CicCaptureRecord::checksumOf($record->fresh()->values));
});

it('lets a running capture take, change and drop records', function () {
    $capture = CicCapture::factory()->create();
    $record = CicCaptureRecord::factory()->for($capture, 'capture')->create();

    $record->forceFill(['values' => ['ID1' => 'ID', 'ID5' => 'X']])->save();
    CicCaptureRecord::query()->insert([[
        'capture_id' => $capture->id, 'record_type' => 'ID', 'record_key' => 'BRW-BULK',
        'values' => json_encode(['ID1' => 'ID']), 'checksum' => CicCaptureRecord::checksumOf(['ID1' => 'ID']),
        'created_at' => now(), 'updated_at' => now(),
    ]]);
    $record->delete();

    expect($capture->records()->pluck('record_key')->all())->toBe(['BRW-BULK']);
});

it('freezes a complete capture and its records', function () {
    $capture = CicCapture::factory()->completeWithRecords(1, 1)->create()->fresh();
    $record = $capture->records()->orderBy('id')->first();
    $values = $record->values;

    expect(fn () => $capture->forceFill(['subject_count' => 9])->save())->toThrow(CicRecordIsFrozenException::class)
        ->and(fn () => $capture->forceFill(['status' => 'failed'])->saveQuietly())->toThrow(CicRecordIsFrozenException::class)
        ->and(fn () => $capture->delete())->toThrow(CicRecordIsFrozenException::class)
        ->and(fn () => $record->forceFill(['values' => ['ID1' => 'ID']])->save())->toThrow(CicRecordIsFrozenException::class)
        ->and(fn () => $record->delete())->toThrow(CicRecordIsFrozenException::class)
        ->and(fn () => CicCaptureRecord::factory()->for($capture, 'capture')->create())->toThrow(CicRecordIsFrozenException::class)
        ->and(fn () => CicCaptureRecord::query()->insert(['capture_id' => $capture->id, 'record_type' => 'ID', 'record_key' => 'X', 'values' => '{}', 'checksum' => 'x']))->toThrow(CicRecordIsFrozenException::class)
        ->and(fn () => CicCaptureRecord::query()->where('capture_id', $capture->id)->update(['checksum' => 'x']))->toThrow(CicRecordIsFrozenException::class)
        ->and(fn () => CicCaptureRecord::query()->where('capture_id', $capture->id)->delete())->toThrow(CicRecordIsFrozenException::class)
        ->and(fn () => CicCapture::query()->whereKey($capture->id)->update(['status' => 'failed']))->toThrow(CicRecordIsFrozenException::class);

    expect($capture->fresh()->status)->toBe('complete')
        ->and($record->fresh()->values)->toBe($values)
        ->and($capture->records()->count())->toBe(2);
});

it('moves a capture forward once and never changes its date or kind', function () {
    $failed = CicCapture::factory()->create();
    $failed->forceFill(['status' => 'failed', 'error' => 'Stopped.'])->save();

    expect(fn () => $failed->forceFill(['status' => 'complete'])->save())->toThrow(CicRecordIsFrozenException::class);

    $running = CicCapture::factory()->create();

    expect(fn () => $running->forceFill(['reference_date' => '2026-01-31'])->save())->toThrow(CicRecordIsFrozenException::class)
        ->and(fn () => $running->forceFill(['kind' => 'month_end'])->save())->toThrow(CicRecordIsFrozenException::class);

    $failed->delete();
    expect(CicCapture::query()->whereKey($failed->id)->exists())->toBeFalse();
});

it('allows one complete month-end capture per reference date', function () {
    CicCapture::factory()->monthEnd('2026-11-30')->complete()->create();
    CicCapture::factory()->monthEnd('2026-11-30')->failed()->create();
    CicCapture::factory()->correction('2026-11-30')->complete()->create();
    CicCapture::factory()->state(['kind' => 'test', 'reference_date' => '2026-11-30'])->complete()->create();
    $second = CicCapture::factory()->monthEnd('2026-11-30')->create();

    expect(fn () => $second->forceFill(['status' => 'complete'])->save())->toThrow(CicRecordIsFrozenException::class)
        ->and(fn () => CicCapture::factory()->monthEnd('2026-11-30')->complete()->create())->toThrow(CicRecordIsFrozenException::class);

    // The database holds it too, whatever the model is bypassed with.
    expect(fn () => DB::table('cic_captures')->insert([
        'reference_date' => '2026-11-30', 'kind' => 'month_end', 'status' => 'complete', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    CicCapture::factory()->monthEnd('2026-12-31')->complete()->create();
    expect(CicCapture::query()->where('kind', 'month_end')->where('status', 'complete')->count())->toBe(2);
});

it('never changes a subject\'s number, person or first report, and never deletes one', function () {
    $subject = CicSubject::factory()->create();
    $number = $subject->subject_no;
    $other = Borrower::factory()->create();

    expect(fn () => $subject->forceFill(['subject_no' => 'BRW-999999'])->save())->toThrow(CicRecordIsFrozenException::class)
        ->and(fn () => $subject->forceFill(['subject_no' => 'BRW-999999'])->saveQuietly())->toThrow(CicRecordIsFrozenException::class)
        ->and(fn () => $subject->forceFill(['borrower_id' => $other->id])->save())->toThrow(CicRecordIsFrozenException::class)
        ->and(fn () => $subject->forceFill(['first_captured_at' => now()->subYear()])->save())->toThrow(CicRecordIsFrozenException::class)
        ->and(fn () => $subject->delete())->toThrow(CicRecordIsFrozenException::class)
        ->and(fn () => CicSubject::query()->whereKey($subject->id)->update(['subject_no' => 'X']))->toThrow(CicRecordIsFrozenException::class);

    $submission = CicSubmission::factory()->create();
    $subject->fresh()->forceFill(['first_reported_submission_id' => $submission->id])->save();
    $another = CicSubmission::factory()->create();

    expect(fn () => $subject->fresh()->forceFill(['first_reported_submission_id' => $another->id])->save())->toThrow(CicRecordIsFrozenException::class)
        ->and($subject->fresh()->first_reported_submission_id)->toBe($submission->id)
        ->and($subject->fresh()->subject_no)->toBe($number);
});

it('keeps the subject number out of mass assignment and names exactly one person', function () {
    $member = Borrower::factory()->create();
    $coMaker = CoMaker::factory()->create();

    expect(fn () => CicSubject::query()->create(['subject_type' => 'member', 'borrower_id' => $member->id, 'subject_no' => 'BRW-1']))
        ->toThrow(CicRecordIsFrozenException::class, 'a subject needs a number');

    $both = new CicSubject(['subject_type' => 'member', 'borrower_id' => $member->id, 'co_maker_id' => $coMaker->id]);
    $both->subject_no = 'BRW-1';
    $wrongType = new CicSubject(['subject_type' => 'co_maker', 'borrower_id' => $member->id]);
    $wrongType->subject_no = 'BRW-1';
    $tooLong = new CicSubject(['subject_type' => 'member', 'borrower_id' => $member->id]);
    $tooLong->subject_no = str_repeat('9', 39);

    expect(fn () => $both->save())->toThrow(CicRecordIsFrozenException::class, 'exactly one')
        ->and(fn () => $wrongType->save())->toThrow(CicRecordIsFrozenException::class, 'exactly one')
        ->and(fn () => $tooLong->save())->toThrow(CicRecordIsFrozenException::class, '1 to 38');

    $subject = new CicSubject(['subject_type' => 'co_maker', 'co_maker_id' => $coMaker->id]);
    $subject->subject_no = $coMaker->co_maker_code;
    $subject->save();

    expect($coMaker->cicSubject->id)->toBe($subject->id);
});

it('keeps at most one active public key and its material unchanged', function () {
    $active = CicPublicKey::factory()->active()->create();
    $pending = CicPublicKey::factory()->create();

    expect(fn () => $pending->forceFill(['status' => 'active'])->save())->toThrow(CicRecordIsFrozenException::class, 'another key is already active')
        ->and(fn () => CicPublicKey::factory()->active()->create())->toThrow(CicRecordIsFrozenException::class)
        ->and(fn () => DB::table('cic_public_keys')->where('id', $pending->id)->update(['status' => 'active']))->toThrow(QueryException::class)
        ->and(fn () => $active->forceFill(['fingerprint' => str_repeat('A', 40)])->save())->toThrow(CicRecordIsFrozenException::class)
        ->and(fn () => $active->forceFill(['armored' => 'x'])->save())->toThrow(CicRecordIsFrozenException::class)
        ->and(fn () => $active->delete())->toThrow(CicRecordIsFrozenException::class)
        ->and(fn () => CicPublicKey::query()->update(['status' => 'retired']))->toThrow(CicRecordIsFrozenException::class);

    // A verification retires the previous key, then activates the new one.
    DB::transaction(function () use ($active, $pending): void {
        $active->refresh()->forceFill(['status' => 'retired', 'retired_at' => now(), 'retire_reason' => 'Replaced.'])->save();
        $pending->refresh()->forceFill(['status' => 'active', 'verified_at' => now()])->save();
    });

    expect(CicPublicKey::activeKey()?->id)->toBe($pending->id)
        ->and(fn () => $active->fresh()->forceFill(['status' => 'active'])->save())->toThrow(CicRecordIsFrozenException::class, "a retired key can't become active")
        ->and(CicPublicKey::query()->count())->toBe(2);
});

it('moves a submission forward only and keeps what it was built from', function () {
    $submission = CicSubmission::factory()->create();

    expect(fn () => $submission->forceFill(['status' => 'submitted'])->save())->toThrow(CicRecordIsFrozenException::class)
        ->and(fn () => $submission->forceFill(['txt_sha256' => str_repeat('0', 64)])->save())->toThrow(CicRecordIsFrozenException::class)
        ->and(fn () => $submission->forceFill(['filename' => 'other.txt'])->save())->toThrow(CicRecordIsFrozenException::class)
        ->and(fn () => $submission->delete())->toThrow(CicRecordIsFrozenException::class);

    $submission->refresh()->forceFill(['status' => 'encrypted', 'gpg_path' => 'cic/a.zip.gpg', 'encrypted_at' => now()])->save();
    $submission->refresh()->forceFill(['download_count' => 1])->save();

    expect(fn () => $submission->refresh()->forceFill(['gpg_path' => 'cic/b.zip.gpg'])->save())->toThrow(CicRecordIsFrozenException::class)
        ->and(fn () => $submission->refresh()->forceFill(['download_count' => 0])->save())->toThrow(CicRecordIsFrozenException::class)
        ->and(fn () => $submission->refresh()->forceFill(['status' => 'generated'])->save())->toThrow(CicRecordIsFrozenException::class);

    $submission->refresh()->forceFill(['status' => 'submitted', 'submitted_at' => now()])->save();
    $submission->refresh()->forceFill(['status' => 'accepted', 'acknowledged_at' => now()])->save();

    expect($submission->fresh()->status)->toBe('accepted')
        ->and($submission->fresh()->download_count)->toBe(1)
        ->and(fn () => $submission->refresh()->forceFill(['status' => 'rejected'])->save())->toThrow(CicRecordIsFrozenException::class);

    $correction = CicSubmission::factory()->correcting($submission)->create();
    expect($correction->corrects->id)->toBe($submission->id)
        ->and($correction->isCorrection())->toBeTrue()
        ->and($submission->corrections()->pluck('id')->all())->toBe([$correction->id]);
});

it('sets a loan\'s CIC contract number once, never by mass assignment', function () {
    $loan = Loan::factory()->create();

    $loan->fill(['cic_contract_no' => 'LN-MASS'])->save();
    expect($loan->fresh()->cic_contract_no)->toBeNull();

    $loan->forceFill(['cic_contract_no' => 'LN-000123'])->save();
    expect($loan->fresh()->cic_contract_no)->toBe('LN-000123')
        ->and(fn () => $loan->fresh()->forceFill(['cic_contract_no' => 'LN-000124'])->save())->toThrow(CicRecordIsFrozenException::class)
        ->and(fn () => Loan::factory()->create()->forceFill(['cic_contract_no' => 'LN-000123'])->save())->toThrow(QueryException::class);

    $override = CicLoanOverride::factory()->for($loan)->create();
    expect($loan->fresh()->cicOverride->id)->toBe($override->id);
});
