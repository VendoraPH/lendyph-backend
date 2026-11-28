<?php

use App\Exceptions\CicRecordIsFrozenException;
use App\Models\AuditLog;
use App\Models\CicCapture;
use App\Models\CicCaptureRecord;
use App\Models\CicSubmission;
use App\Services\Cic\Contracts\ValidatesCicCapture;
use App\Services\Cic\File\CicFileBuilder;
use App\Services\Cic\Submissions\CicSubmissionRefusal;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Cic\Submissions\CicSubmissionsTestHelpers;
use Tests\TestCase;

uses(TestCase::class, CicSubmissionsTestHelpers::class);

/*
 * The submission side of the CIC #410 review:
 * - M-H1: a never-filed month is refiled as a standard file from a correction
 *   capture of its month-end; only one standard file of a month goes to CIC.
 * - S-M2: a file's page reads cached validation and the stored correction diff.
 * - S-L4: comments and every field refuse control and invisible characters.
 * - S-L5: submissions refuse bulk and quiet writes.
 * - S-L6: generate and encrypt re-check the capture's checksums.
 * - S-L9: a same-second filename collision is a clean 409.
 */

beforeEach(function () {
    $this->setUpCic();
    Storage::fake('private');
});

afterEach(function () {
    $this->tearDownTestKeys();
});

function cicGenerate(object $test, array $body)
{
    return $test->actingAs($test->cicAll())->postJson('/api/cic/submissions', $body);
}

it('makes a standard file from a correction capture of a never-filed month-end, from the correction\'s records', function () {
    $monthEnd = $this->captureWith([['ID', 'BRW-00000001'], ['CI', 'LN-000001']]);
    $correction = $this->captureWith([['ID', 'BRW-00000001'], ['CI', 'LN-000001'], ['CN', 'LN-000009']], CicCapture::KIND_CORRECTION, corrects: $monthEnd);
    // A file of the month that never went to CIC doesn't block it.
    CicSubmission::factory()->encrypted()->create(['capture_id' => $monthEnd->id, 'reference_date' => '2026-10-31']);

    $response = cicGenerate($this, ['capture_id' => $correction->id, 'submission_type' => 0])
        ->assertCreated()
        ->assertJsonPath('data.submission_type', 0)
        ->assertJsonPath('data.capture.id', $correction->id)
        ->assertJsonPath('data.capture.kind', 'correction')
        ->assertJsonPath('data.record_count', 3)
        ->assertJsonPath('data.counts_by_type.CN', 1)
        ->assertJsonPath('data.correction', null);

    $submission = CicSubmission::query()->findOrFail($response->json('data.id'));
    expect(explode("\r\n", app(CicFileBuilder::class)->forSubmission($submission)->contents)[0])->toStartWith('HD|TEST1234|31102026|1.0|0|');

    $audit = AuditLog::query()->where('auditable_type', CicSubmission::class)->where('auditable_id', $submission->id)->sole();
    expect($audit->new_values['capture_kind'])->toBe('correction')
        ->and($audit->description)->toContain("generated from correction capture #{$correction->id}");
});

it('refuses a standard file from a correction capture once a file of that month went to CIC', function (string $status, bool $fromCorrection) {
    $monthEnd = $this->captureWith([['ID', 'BRW-00000001']]);
    $earlier = $this->captureWith([['ID', 'BRW-00000001'], ['CI', 'LN-000001']], CicCapture::KIND_CORRECTION, corrects: $monthEnd);
    $correction = $this->captureWith([['ID', 'BRW-00000001'], ['CI', 'LN-000002']], CicCapture::KIND_CORRECTION, corrects: $monthEnd);
    $filed = CicSubmission::factory()->submitted()->create(['capture_id' => ($fromCorrection ? $earlier : $monthEnd)->id, 'reference_date' => '2026-10-31']);

    if ($status !== CicSubmission::STATUS_SUBMITTED) {
        $filed->forceFill(['status' => $status, 'acknowledged_by' => $this->cicAll()->id, 'acknowledged_at' => now()])->save();
    }

    cicGenerate($this, ['capture_id' => $correction->id, 'submission_type' => 0])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'month_already_filed')
        ->assertJsonPath('message', "The 2026-10-31 file {$filed->filename} was already ".match ($status) {
            'submitted' => 'submitted to CIC',
            'accepted' => 'accepted by CIC',
            'rejected' => 'rejected by CIC',
        }.', so no new standard file can be made or submitted for that month. Make a correction file of it instead.');

    expect(CicSubmission::query()->where('capture_id', $correction->id)->exists())->toBeFalse();
})->with([
    'submitted month-end file' => ['submitted', false],
    'accepted file of another correction' => ['accepted', true],
    'rejected month-end file' => ['rejected', false],
]);

it('still refuses a standard file from a correction capture with no month-end behind it', function () {
    $test = $this->captureWith([['ID', 'BRW-00000001']], CicCapture::KIND_TEST);

    cicGenerate($this, ['capture_id' => $this->captureWith([['ID', 'BRW-00000001']], CicCapture::KIND_CORRECTION, corrects: $test)->id, 'submission_type' => 0])
        ->assertUnprocessable()->assertJsonPath('code', 'correction_capture');
});

it('marks only one standard file of a month submitted, while corrections of it still go', function () {
    $user = $this->cicAll();
    $monthEnd = $this->captureWith([['ID', 'BRW-00000001']]);
    $correction = $this->captureWith([['ID', 'BRW-00000001'], ['CI', 'LN-000001']], CicCapture::KIND_CORRECTION, corrects: $monthEnd);
    $fromMonthEnd = CicSubmission::factory()->encrypted()->create(['capture_id' => $monthEnd->id, 'reference_date' => '2026-10-31']);
    $fromCorrection = CicSubmission::factory()->encrypted()->create(['capture_id' => $correction->id, 'reference_date' => '2026-10-31']);

    $this->actingAs($user)->postJson("/api/cic/submissions/{$fromCorrection->id}/mark-submitted")->assertOk()->assertJsonPath('data.status', 'submitted');
    $this->actingAs($user)->postJson("/api/cic/submissions/{$fromMonthEnd->id}/mark-submitted")
        ->assertUnprocessable()->assertJsonPath('code', 'month_already_filed');

    $fix = CicSubmission::factory()->encrypted()->correcting($fromCorrection->fresh())->create(['capture_id' => $correction->id]);
    $this->actingAs($user)->postJson("/api/cic/submissions/{$fix->id}/mark-submitted")->assertOk();

    expect($fromMonthEnd->fresh()->status)->toBe('encrypted');
});

it('corrects a standard file made from a correction capture, against that capture', function () {
    $monthEnd = $this->captureWith([['ID', 'BRW-00000001']]);
    $first = $this->captureWith([['ID', 'BRW-00000001'], ['CI', 'LN-000001']], CicCapture::KIND_CORRECTION, corrects: $monthEnd);
    $filed = CicSubmission::factory()->submitted()->create(['capture_id' => $first->id, 'reference_date' => '2026-10-31']);
    $second = $this->captureWith([['ID', 'BRW-00000001'], ['CI', 'LN-000001'], ['CN', 'LN-000009']], CicCapture::KIND_CORRECTION, corrects: $monthEnd);

    cicGenerate($this, ['capture_id' => $second->id, 'submission_type' => 1, 'corrects_submission_id' => $filed->id, 'correction_reason' => 'A loan was left out.'])
        ->assertCreated()
        ->assertJsonPath('data.correction.affected_records', [['record_type' => 'CN', 'record_key' => 'LN-000009', 'change' => 'added']]);
});

it('stores a correction\'s affected records once and serves a file\'s page from cached validation', function () {
    $counter = new stdClass;
    $counter->calls = 0;
    app()->instance(ValidatesCicCapture::class, new class($counter) implements ValidatesCicCapture
    {
        public function __construct(private readonly stdClass $counter) {}

        public function issues(CicCapture $capture): array
        {
            $this->counter->calls++;

            return [];
        }
    });

    $original = $this->captureWith([['ID', 'BRW-00000001', ['ID5' => 'BRW-00000001', 'ID9' => '01011990']]]);
    $filed = CicSubmission::factory()->submitted()->create(['capture_id' => $original->id, 'reference_date' => '2026-10-31']);
    $correction = $this->captureWith([['ID', 'BRW-00000001', ['ID5' => 'BRW-00000001', 'ID9' => '02011990']]], CicCapture::KIND_CORRECTION, corrects: $original);
    $user = $this->cicAll();

    $id = $this->actingAs($user)->postJson('/api/cic/submissions', [
        'capture_id' => $correction->id, 'submission_type' => 1, 'corrects_submission_id' => $filed->id, 'correction_reason' => 'Wrong birthdate.',
    ])->assertCreated()->json('data.id');

    expect(CicSubmission::query()->findOrFail($id)->affected_records)->toEqual([['record_type' => 'ID', 'record_key' => 'BRW-00000001', 'change' => 'changed']])
        ->and($counter->calls)->toBe(1);

    $this->actingAs($user)->getJson("/api/cic/submissions/{$id}")->assertOk();
    expect($counter->calls)->toBe(2);

    $captureRecordQueries = 0;
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$captureRecordQueries) {
        // Only the per-type count (an index read) touches the records.
        if (str_contains($query->sql, 'cic_capture_records') && ! str_contains($query->sql, 'COUNT(*)')) {
            $captureRecordQueries++;
        }
    });

    $this->actingAs($user)->getJson("/api/cic/submissions/{$id}")
        ->assertOk()
        ->assertJsonPath('data.validation.errors', 0)
        ->assertJsonPath('data.correction.affected_records.0.record_key', 'BRW-00000001');
    $this->actingAs($user)->getJson('/api/cic/submissions')->assertOk();

    expect($counter->calls)->toBe(2)->and($captureRecordQueries)->toBe(0);
});

it('refuses comments holding control or invisible characters, in words', function (string $comments) {
    cicGenerate($this, ['capture_id' => $this->simpleCapture()->id, 'submission_type' => 0, 'comments' => $comments])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['comments' => 'The comments can\'t hold a "|", a line break, or a control or invisible character.']);
})->with([
    'NUL' => "a\0b",
    'vertical tab' => "a\x0Bb",
    'form feed' => "a\x0Cb",
    'next line' => "a\u{0085}b",
    'line separator' => "a\u{2028}b",
    'paragraph separator' => "a\u{2029}b",
    'byte order mark' => "a\u{FEFF}b",
    'zero-width space' => "a\u{200B}b",
]);

it('refuses a file whose records or comments hold control or invisible characters, HD included', function () {
    $capture = $this->captureWith([['ID', 'BRW-00000001', ['ID5' => 'BRW-00000001', 'ID7' => "DELA\u{2028}CRUZ"]], ['CI', 'LN-000001', ['CI5' => "LN\x0B1"]]]);

    $response = cicGenerate($this, ['capture_id' => $capture->id, 'submission_type' => 0])
        ->assertUnprocessable()->assertJsonPath('code', 'validation_errors')->assertJsonPath('data.validation.errors', 2);
    expect(collect($response->json('data.validation.top_issues'))->pluck('code')->unique()->all())->toBe(['unsafe_value'])
        ->and($response->getContent())->not->toContain('DELA');

    $clean = $this->simpleCapture(CicCapture::KIND_TEST);
    $builder = app(CicFileBuilder::class);

    foreach (["Oct\u{2029}", "Oct\0", "Oct\u{FEFF}"] as $comments) {
        expect(fn () => $builder->build($clean, 0, $comments, now()))->toThrow(CicSubmissionRefusal::class);
    }

    expect($builder->build($clean, 0, 'October file', now())->recordCount)->toBe(2);
});

it('refuses bulk, quiet and incrementing writes to a submission', function () {
    $submission = CicSubmission::factory()->create();

    expect(fn () => CicSubmission::query()->whereKey($submission->id)->update(['status' => 'accepted']))->toThrow(CicRecordIsFrozenException::class)
        ->and(fn () => CicSubmission::query()->whereKey($submission->id)->delete())->toThrow(CicRecordIsFrozenException::class)
        ->and(fn () => $submission->fresh()->forceFill(['status' => 'accepted'])->saveQuietly())->toThrow(CicRecordIsFrozenException::class)
        ->and(fn () => CicSubmission::withoutEvents(fn () => $submission->fresh()->forceFill(['txt_sha256' => str_repeat('0', 64)])->save()))->toThrow(CicRecordIsFrozenException::class)
        ->and(fn () => $submission->fresh()->increment('download_count'))->toThrow(CicRecordIsFrozenException::class)
        ->and(fn () => $submission->fresh()->deleteQuietly())->toThrow(CicRecordIsFrozenException::class)
        ->and($submission->fresh()->status)->toBe('generated')
        ->and(fn () => $submission->fresh()->forceFill(['affected_records' => []])->save())->toThrow(CicRecordIsFrozenException::class);

    $submission->fresh()->forceFill(['status' => 'encrypted', 'gpg_path' => 'cic/a.zip.gpg', 'encrypted_at' => now()])->saveQuietly();
    expect($submission->fresh()->status)->toBe('encrypted');
});

it('refuses to generate from a capture whose records no longer match their checksums', function () {
    $capture = $this->simpleCapture();
    $record = CicCaptureRecord::query()->where('capture_id', $capture->id)->where('record_type', 'ID')->sole();

    // A record's values edited behind the model: its own checksum no longer matches.
    DB::table('cic_capture_records')->where('id', $record->id)->update(['values' => json_encode(['ID1' => 'ID', 'ID5' => 'BRW-00000099'])]);
    cicGenerate($this, ['capture_id' => $capture->id, 'submission_type' => 0])
        ->assertConflict()->assertJsonPath('code', 'capture_changed');

    // Values and record checksum both edited: the capture's checksum no longer matches.
    $values = ['ID1' => 'ID', 'ID5' => 'BRW-00000099'];
    DB::table('cic_capture_records')->where('id', $record->id)->update(['checksum' => CicCaptureRecord::checksumOf($values)]);
    cicGenerate($this, ['capture_id' => $capture->id, 'submission_type' => 0])
        ->assertConflict()
        ->assertJsonPath('code', 'capture_changed')
        ->assertJsonPath('message', "Capture #{$capture->id}'s records no longer match its checksum, so no file was made or encrypted from it. Take a new capture.");

    expect(CicSubmission::query()->count())->toBe(0);
});

it('refuses to encrypt a file whose capture changed after generation', function () {
    $this->activateTestKey($this->makeTestKey());
    $user = $this->cicAll();
    $capture = $this->simpleCapture();
    $id = $this->actingAs($user)->postJson('/api/cic/submissions', ['capture_id' => $capture->id, 'submission_type' => 0])->assertCreated()->json('data.id');
    $values = ['ID1' => 'ID', 'ID5' => 'BRW-00000099'];
    DB::table('cic_capture_records')->where('capture_id', $capture->id)->where('record_type', 'ID')
        ->update(['values' => json_encode($values), 'checksum' => CicCaptureRecord::checksumOf($values)]);

    $this->actingAs($user)->postJson("/api/cic/submissions/{$id}/encrypt", ['confirm' => true])
        ->assertConflict()->assertJsonPath('code', 'capture_changed');

    expect(CicSubmission::query()->findOrFail($id)->status)->toBe('generated');
});

it('answers a same-second filename collision with a clean 409, never a 500', function () {
    $capture = $this->simpleCapture();
    $this->travelTo(now()->setDateTime(2026, 11, 25, 14, 30, 10));
    $user = $this->cicAll();

    // Another request saves the same name between the check and the insert.
    CicSubmission::creating(function (CicSubmission $submission) use ($capture, $user) {
        if (DB::table('cic_submissions')->where('filename', $submission->filename)->doesntExist()) {
            DB::table('cic_submissions')->insert([
                'capture_id' => $capture->id, 'reference_date' => '2026-10-31', 'submission_type' => 0,
                'filename' => $submission->filename, 'record_count' => 2, 'txt_sha256' => str_repeat('a', 64),
                'status' => 'generated', 'generated_by' => $user->id, 'generated_at' => now(),
                'download_count' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    $this->actingAs($user)->postJson('/api/cic/submissions', ['capture_id' => $capture->id, 'submission_type' => 0])
        ->assertConflict()
        ->assertJsonPath('code', 'filename_taken')
        ->assertJsonPath('message', 'Another file with the same name was made this second. Try again in a moment.');
});
