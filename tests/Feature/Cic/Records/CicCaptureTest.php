<?php

use App\Exceptions\CicRecordIsFrozenException;
use App\Models\AuditLog;
use App\Models\CicCapture;
use App\Models\CicCaptureRecord;
use App\Models\CicSubject;
use App\Models\Repayment;
use App\Services\Cic\Capture\CicCaptureService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Cic\Records\CicRecordsFixtures;
use Tests\TestCase;

uses(TestCase::class, CicRecordsFixtures::class);

/*
 * The month-end capture: it freezes every record with checksums, registers
 * subjects and contract numbers once, is idempotent per date, can't change
 * once complete, refuses test captures while the switch is off, and a
 * correction rebuilds subjects while keeping the month-end's contracts.
 */

beforeEach(fn () => $this->cicOn());

function cicCapture(string $kind = CicCapture::KIND_MONTH_END, string $date = '2026-11-30', ?CicCapture $corrects = null): CicCapture
{
    return app(CicCaptureService::class)->capture(Carbon::parse($date), $kind, null, $corrects);
}

it('captures every record with checksums, registers subjects and freezes contract numbers', function () {
    $loan = $this->cicLoan();

    $capture = cicCapture();
    $records = CicCaptureRecord::query()->where('capture_id', $capture->id)->get()->keyBy('record_type');
    $subject = CicSubject::query()->where('borrower_id', $loan->borrower_id)->firstOrFail();

    expect($capture->status)->toBe('complete')
        ->and([$capture->subject_count, $capture->contract_count, $capture->event_count])->toBe([1, 1, 0])
        ->and($capture->checksum)->toBe($capture->computeChecksum())
        ->and($records['ID']->record_key)->toBe($loan->borrower->borrower_code)
        ->and($records['ID']->cic_subject_id)->toBe($subject->id)
        ->and($records['CI']->loan_id)->toBe($loan->id)
        ->and($records['CI']->checksum)->toBe(CicCaptureRecord::checksumOf($records['CI']->values))
        ->and($subject->subject_no)->toBe($loan->borrower->borrower_code)
        ->and($subject->first_captured_at)->not->toBeNull()
        ->and($loan->fresh()->cic_contract_no)->toBe($loan->loan_account_number);

    $audit = AuditLog::query()->where('action', 'cic_capture_completed')->latest('id')->firstOrFail();
    expect($audit->auditable_type)->toBe(CicCapture::class)->and($audit->auditable_id)->toBe($capture->id);
    expect($audit->new_values)->toMatchArray(['capture_id' => $capture->id, 'kind' => 'month_end', 'subject_count' => 1, 'contract_count' => 1])
        ->and($audit->description)->toContain("month-end capture #{$capture->id}")
        ->and($audit->description)->not->toContain($loan->borrower->last_name);
});

it('is idempotent per month end: the complete capture comes back untouched', function () {
    $this->cicLoan();
    $first = cicCapture();
    $this->cicLoan();

    $again = cicCapture();

    expect($again->id)->toBe($first->id)
        ->and($again->checksum)->toBe($first->checksum)
        ->and(CicCapture::query()->where('kind', 'month_end')->count())->toBe(1)
        ->and(CicCaptureRecord::query()->where('capture_id', $first->id)->count())->toBe(2);
});

it('keeps a complete capture as it was: later changes reach only new captures', function () {
    $loan = $this->cicLoan();
    $capture = cicCapture();
    $record = CicCaptureRecord::query()->where('capture_id', $capture->id)->where('record_type', 'CI')->firstOrFail();
    $subject = CicSubject::query()->where('borrower_id', $loan->borrower_id)->firstOrFail();

    $record->values = ['CI1' => 'CI'];
    expect(fn () => $record->save())->toThrow(CicRecordIsFrozenException::class);

    Repayment::factory()->create(['loan_id' => $loan->id, 'payment_date' => '2026-12-05', 'amount_paid' => 999, 'status' => 'posted']);
    Carbon::setTestNow('2026-12-31 23:58:00');
    $next = cicCapture(date: '2026-12-31');

    expect($record->fresh()->checksum)->toBe($record->checksum)
        ->and($capture->fresh()->checksum)->toBe($capture->checksum)
        ->and(CicCaptureRecord::query()->where('capture_id', $next->id)->where('record_type', 'CI')->value('values')['CI28'])->toBe('999')
        ->and($subject->fresh()->first_captured_at->equalTo($subject->first_captured_at))->toBeTrue()
        ->and(CicSubject::query()->count())->toBe(1);
});

it('refuses a test capture while test captures are off, and dates one today when on', function () {
    $this->cicLoan();

    expect(fn () => cicCapture(CicCapture::KIND_TEST))->toThrow(ValidationException::class, 'Test captures are off on this server.');

    config(['cic.test_captures' => true]);
    $test = cicCapture(CicCapture::KIND_TEST);

    expect($test->kind)->toBe('test')->and($test->status)->toBe('complete')
        ->and(cicCapture(CicCapture::KIND_TEST)->id)->not->toBe($test->id);
});

it('corrects a month end: subjects rebuilt from today\'s member data, contracts kept from the month-end capture', function () {
    $loan = $this->cicLoan();
    $original = cicCapture();
    $originalContract = CicCaptureRecord::query()->where('capture_id', $original->id)->where('record_type', 'CI')->firstOrFail();

    $loan->borrower->update(['first_name' => 'Mariana']);
    Repayment::factory()->create(['loan_id' => $loan->id, 'payment_date' => '2026-11-29', 'amount_paid' => 777, 'status' => 'posted']);

    $correction = cicCapture(CicCapture::KIND_CORRECTION, corrects: $original);
    $records = CicCaptureRecord::query()->where('capture_id', $correction->id)->get()->keyBy('record_type');

    expect($correction->status)->toBe('complete')
        ->and($correction->corrects_capture_id)->toBe($original->id)
        ->and($records['ID']->values['ID7'])->toBe('Mariana')
        ->and($records['CI']->values)->toBe($originalContract->values)
        ->and($records['CI']->checksum)->toBe($originalContract->checksum)
        ->and($correction->checksum)->not->toBe($original->checksum);
});

it('refuses a correction of anything but a complete month-end capture of the same date', function () {
    $test = CicCapture::factory()->complete()->create(['reference_date' => '2026-11-30']);
    $monthEnd = CicCapture::factory()->monthEnd('2026-10-31')->complete()->create();

    expect(fn () => cicCapture(CicCapture::KIND_CORRECTION, corrects: $test))->toThrow(ValidationException::class, 'A correction corrects a complete month-end capture.')
        ->and(fn () => cicCapture(CicCapture::KIND_CORRECTION, corrects: $monthEnd))->toThrow(ValidationException::class, 'same reference date')
        ->and(fn () => cicCapture(CicCapture::KIND_CORRECTION))->toThrow(ValidationException::class);
});

it('corrects a complete test capture only while test captures are on', function () {
    $this->cicLoan();
    $test = CicCapture::factory()->complete()->create(['reference_date' => '2026-11-30']);

    expect(fn () => cicCapture(CicCapture::KIND_CORRECTION, corrects: $test))->toThrow(ValidationException::class, 'test captures on');

    config(['cic.test_captures' => true]);
    $correction = cicCapture(CicCapture::KIND_CORRECTION, corrects: $test);

    expect($correction->status)->toBe('complete')
        ->and($correction->corrects_capture_id)->toBe($test->id)
        ->and($correction->reference_date->toDateString())->toBe('2026-11-30');
});

it('marks a capture that can\'t finish as failed, in words, and audits it', function () {
    $loan = $this->cicLoan();
    DB::table('borrowers')->where('id', $loan->borrower_id)->update(['borrower_code' => '']);

    $capture = cicCapture();

    expect($capture->status)->toBe('failed')
        ->and($capture->error)->toBe(CicCaptureService::FAILURE)
        ->and(AuditLog::query()->where('action', 'cic_capture_failed')->exists())->toBeTrue()
        ->and(app(CicCaptureService::class)->completeMonthEnd(Carbon::parse('2026-11-30')))->toBeNull();
});

it('schedules the month-end capture at 23:58 Manila on the month\'s last day', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn (Event $e) => str_contains((string) $e->command, 'cic:capture-month-end'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->timezone)->toBe('Asia/Manila')
        ->and($events->first()->expression)->toMatch('/^58 23 (L|28|29|30|31) \* \*$/');
});

it('does nothing while CIC is off, captures a month end, and skips one already captured', function () {
    $this->cicLoan();
    config(['cic.enabled' => false]);
    $this->artisan('cic:capture-month-end')->expectsOutputToContain('CIC reporting is off')->assertSuccessful();
    expect(CicCapture::query()->count())->toBe(0);

    config(['cic.enabled' => true]);
    $this->artisan('cic:capture-month-end')->expectsOutputToContain('1 subjects, 1 contracts')->assertSuccessful();
    $this->artisan('cic:capture-month-end')->expectsOutputToContain('already has a complete month-end capture')->assertSuccessful();
    $this->artisan('cic:capture-month-end --date=2026-11-15')->expectsOutputToContain('last day')->assertFailed();
    $this->artisan('cic:capture-month-end --date=2026-12-31')->expectsOutputToContain('hasn\'t come yet')->assertFailed();
    $this->artisan('cic:capture-month-end --date=2026-10-31')->expectsOutputToContain('today\'s figures')->assertSuccessful();

    expect(CicCapture::query()->where('kind', 'month_end')->where('status', 'complete')->pluck('reference_date')->map->toDateString()->sort()->values()->all())
        ->toBe(['2026-10-31', '2026-11-30']);
});
