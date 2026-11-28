<?php

use App\Models\AuditLog;
use App\Models\CicCapture;
use App\Models\CicPublicKey;
use App\Models\CicSetting;
use App\Models\CicSubmission;
use App\Services\Cic\CicAccess;
use App\Services\Cic\CicLayouts;
use App\Services\Cic\File\CicFileBuilder;
use Tests\Feature\Cic\Submissions\CicSubmissionsTestHelpers;
use Tests\TestCase;

uses(TestCase::class, CicSubmissionsTestHelpers::class);

/*
 * POST /cic/submissions, GET /cic/submissions and GET /cic/submissions/{id}:
 * generation from a complete capture and its refusals, corrections (type 1),
 * the history list and the pre-encryption review. No member's data in any
 * answer or audit row.
 */

beforeEach(function () {
    $this->setUpCic();
});

function cicError(string $field, string $code = 'required', string $severity = 'error'): array
{
    return ['record_type' => substr($field, 0, 2), 'record_key' => 'BRW-00000001', 'field_id' => $field, 'severity' => $severity, 'code' => $code, 'message' => 'Juan Dela Cruz has no birthdate.'];
}

it('generates a standard file and stores only its name, counts and checksum', function () {
    $capture = $this->captureWith([
        ['ID', 'BRW-00000001', ['ID5' => 'BRW-00000001', 'ID7' => 'DELA CRUZ']],
        ['CI', 'LN-000001'],
    ]);
    $user = $this->cicUser([CicAccess::PREPARE, CicAccess::VIEW]);
    $this->travelTo(now()->setDateTime(2026, 11, 25, 14, 30, 10));

    $response = $this->actingAs($user)->postJson('/api/cic/submissions', [
        'capture_id' => $capture->id, 'submission_type' => 0, 'comments' => 'October file',
    ])->assertCreated()
        ->assertJsonPath('data.status', 'generated')
        ->assertJsonPath('data.filename', 'TEST1234_CSDF_20261125143010.txt')
        ->assertJsonPath('data.record_count', 2)
        ->assertJsonPath('data.counts_by_type', ['ID' => 1, 'CI' => 1, 'CN' => 0, 'NE' => 0])
        ->assertJsonPath('data.reference_date', '2026-10-31')
        ->assertJsonPath('data.submission_type', 0)
        ->assertJsonPath('data.provider_code', 'TEST1234')
        ->assertJsonPath('data.comments', 'October file')
        ->assertJsonPath('data.generated_by.id', $user->id)
        ->assertJsonPath('data.correction', null)
        ->assertJsonPath('data.history.0.status', 'generated');

    $submission = CicSubmission::query()->findOrFail($response->json('data.id'));
    $rebuilt = app(CicFileBuilder::class)->forSubmission($submission);

    expect($submission->txt_sha256)->toBe($rebuilt->sha256())
        ->and($response->json('data.txt_sha256'))->toBe($rebuilt->sha256())
        ->and($submission->gpg_path)->toBeNull()
        ->and($response->getContent())->not->toContain('DELA CRUZ');

    $audit = AuditLog::query()->where('auditable_type', CicSubmission::class)->where('auditable_id', $submission->id)->sole();
    expect($audit->action)->toBe('cic_file_generated')
        ->and($audit->new_values['record_count'])->toBe(2)
        ->and($audit->description)->toContain('TEST1234_CSDF_20261125143010.txt')
        ->and(json_encode($audit->toArray()))->not->toContain('DELA CRUZ');
});

it('needs cic:prepare and head-office access, before any field check', function () {
    $this->actingAs($this->cicUser([CicAccess::VIEW, CicAccess::SUBMIT]))
        ->postJson('/api/cic/submissions', [])
        ->assertForbidden()
        ->assertJsonPath('message', 'You don\'t have access to prepare CIC files.');
});

it('refuses a capture that is not complete', function () {
    $capture = $this->captureWith([['ID', 'BRW-00000001']], complete: false);

    $this->actingAs($this->cicAll())->postJson('/api/cic/submissions', ['capture_id' => $capture->id, 'submission_type' => 0])
        ->assertUnprocessable()->assertJsonPath('code', 'capture_incomplete');

    expect(CicSubmission::query()->count())->toBe(0);
});

it('refuses a provider code that is not 8 letters or digits', function () {
    CicSetting::query()->orderBy('id')->firstOrFail()->forceFill(['provider_code' => 'ABC'])->save();

    $this->actingAs($this->cicAll())->postJson('/api/cic/submissions', ['capture_id' => $this->simpleCapture()->id, 'submission_type' => 0])
        ->assertUnprocessable()->assertJsonPath('code', 'provider_code_invalid')
        ->assertJsonPath('message', CicFileBuilder::PROVIDER_CODE_REFUSAL);
});

it('refuses while errors remain, with counts and top issues by field and no member values', function () {
    $this->fakeIssues([cicError('ID7'), cicError('ID7'), cicError('CI20', 'too_long'), cicError('ID30', 'blank', 'warning')]);

    $response = $this->actingAs($this->cicAll())
        ->postJson('/api/cic/submissions', ['capture_id' => $this->simpleCapture()->id, 'submission_type' => 0, 'confirm_warnings' => true])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_errors')
        ->assertJsonPath('data.validation.errors', 3)
        ->assertJsonPath('data.validation.warnings', 1)
        ->assertJsonPath('data.validation.top_issues.0.field_id', 'ID7')
        ->assertJsonPath('data.validation.top_issues.0.count', 2)
        ->assertJsonPath('data.validation.top_issues.0.severity', 'error')
        ->assertJsonPath('data.validation.top_issues.0.field_name', CicLayouts::field('ID7')['name']);

    expect($response->json('message'))->toContain('3 errors')
        ->and($response->getContent())->not->toContain('Juan')
        ->and($response->getContent())->not->toContain('BRW-00000001')
        ->and(CicSubmission::query()->count())->toBe(0);
});

it('refuses warnings until they are confirmed', function () {
    $this->fakeIssues([cicError('ID30', 'blank', 'warning')]);
    $capture = $this->simpleCapture();
    $user = $this->cicAll();

    $this->actingAs($user)->postJson('/api/cic/submissions', ['capture_id' => $capture->id, 'submission_type' => 0])
        ->assertUnprocessable()->assertJsonPath('code', 'warnings_unconfirmed')->assertJsonPath('data.validation.warnings', 1);

    $this->actingAs($user)->postJson('/api/cic/submissions', ['capture_id' => $capture->id, 'submission_type' => 0, 'confirm_warnings' => '1'])
        ->assertCreated()->assertJsonPath('data.validation.warnings', 1);
});

it('refuses a value that would break the file', function () {
    $capture = $this->captureWith([['ID', 'BRW-00000001', ['ID5' => 'BRW-00000001', 'ID7' => 'A|B']]]);

    $this->actingAs($this->cicAll())->postJson('/api/cic/submissions', ['capture_id' => $capture->id, 'submission_type' => 0])
        ->assertUnprocessable()->assertJsonPath('code', 'validation_errors')
        ->assertJsonPath('data.validation.top_issues.0.code', CicFileBuilder::UNSAFE_VALUE);
});

it('validates the body in words', function () {
    $capture = $this->simpleCapture();
    $user = $this->cicAll();

    $this->actingAs($user)->postJson('/api/cic/submissions', ['capture_id' => $capture->id, 'submission_type' => 2])
        ->assertUnprocessable()->assertJsonValidationErrors(['submission_type' => 'The submission type is 0 (standard reporting) or 1 (correction).']);
    $this->actingAs($user)->postJson('/api/cic/submissions', ['capture_id' => $capture->id, 'submission_type' => 0, 'comments' => 'a|b'])
        ->assertUnprocessable()->assertJsonValidationErrors(['comments' => 'The comments can\'t hold a "|", a line break, or a control or invisible character.']);
    $this->actingAs($user)->postJson('/api/cic/submissions', ['capture_id' => $capture->id, 'submission_type' => 0, 'comments' => str_repeat('a', 101)])
        ->assertUnprocessable()->assertJsonValidationErrors('comments');
    $this->actingAs($user)->postJson('/api/cic/submissions', ['capture_id' => $capture->id, 'submission_type' => 0, 'correction_reason' => 'x'])
        ->assertUnprocessable()->assertJsonValidationErrors(['correction_reason' => 'Only a correction takes a correction reason.']);
    $this->actingAs($user)->postJson('/api/cic/submissions', ['capture_id' => 999999, 'submission_type' => 0])
        ->assertUnprocessable()->assertJsonValidationErrors(['capture_id' => 'There is no such capture.']);
});

it('refuses a standard file from a correction capture', function () {
    $this->actingAs($this->cicAll())
        ->postJson('/api/cic/submissions', ['capture_id' => $this->simpleCapture(CicCapture::KIND_CORRECTION)->id, 'submission_type' => 0])
        ->assertUnprocessable()->assertJsonPath('code', 'correction_capture');
});

/** A submitted month-end file and a correction capture of the same date: one record changed, one added. */
function cicCorrectionSetup(object $test): array
{
    $originalCapture = $test->captureWith([
        ['ID', 'BRW-00000001', ['ID5' => 'BRW-00000001', 'ID9' => '01011990']],
        ['ID', 'BRW-00000002', ['ID5' => 'BRW-00000002']],
        ['CI', 'LN-000001'],
    ]);
    $original = CicSubmission::factory()->submitted()->create(['capture_id' => $originalCapture->id, 'reference_date' => '2026-10-31']);
    $correction = $test->captureWith([
        ['ID', 'BRW-00000001', ['ID5' => 'BRW-00000001', 'ID9' => '02011990']],
        ['ID', 'BRW-00000002', ['ID5' => 'BRW-00000002']],
        ['CI', 'LN-000001'],
        ['CN', 'LN-000009'],
    ], CicCapture::KIND_CORRECTION, corrects: $originalCapture);

    return [$original, $correction];
}

it('generates a correction listing the affected records, and never changes the original', function () {
    [$original, $correction] = cicCorrectionSetup($this);
    $before = $original->fresh()->getAttributes();

    $response = $this->actingAs($this->cicAll())->postJson('/api/cic/submissions', [
        'capture_id' => $correction->id, 'submission_type' => 1,
        'corrects_submission_id' => $original->id, 'correction_reason' => 'A member\'s birthdate was wrong.',
    ])->assertCreated()
        ->assertJsonPath('data.submission_type', 1)
        ->assertJsonPath('data.corrects_submission_id', $original->id)
        ->assertJsonPath('data.correction.reason', 'A member\'s birthdate was wrong.')
        ->assertJsonPath('data.correction.corrects.id', $original->id)
        ->assertJsonPath('data.correction.affected_count', 2)
        ->assertJsonPath('data.correction.affected_records', [
            ['record_type' => 'ID', 'record_key' => 'BRW-00000001', 'change' => 'changed'],
            ['record_type' => 'CN', 'record_key' => 'LN-000009', 'change' => 'added'],
        ]);

    $submission = CicSubmission::query()->findOrFail($response->json('data.id'));
    expect(explode("\r\n", app(CicFileBuilder::class)->forSubmission($submission)->contents)[0])->toStartWith('HD|TEST1234|31102026|1.0|1|')
        ->and($original->fresh()->getAttributes())->toBe($before)
        ->and($response->getContent())->not->toContain('02011990');

    $this->actingAs($this->cicAll())->getJson("/api/cic/submissions/{$original->id}")
        ->assertOk()->assertJsonPath('data.corrections.0.id', $submission->id);
});

it('refuses a correction of a file not yet submitted', function () {
    [$original, $correction] = cicCorrectionSetup($this);
    $generated = CicSubmission::factory()->create(['capture_id' => $original->capture_id, 'reference_date' => '2026-10-31']);

    $this->actingAs($this->cicAll())->postJson('/api/cic/submissions', [
        'capture_id' => $correction->id, 'submission_type' => 1, 'corrects_submission_id' => $generated->id, 'correction_reason' => 'Wrong birthdate.',
    ])->assertUnprocessable()->assertJsonPath('code', 'not_correctable');
});

it('refuses a correction from another kind of capture or another reference date', function () {
    [$original] = cicCorrectionSetup($this);
    $user = $this->cicAll();
    $body = ['submission_type' => 1, 'corrects_submission_id' => $original->id, 'correction_reason' => 'Wrong birthdate.'];

    $this->actingAs($user)->postJson('/api/cic/submissions', [...$body, 'capture_id' => $this->simpleCapture(CicCapture::KIND_TEST)->id])
        ->assertUnprocessable()->assertJsonPath('code', 'not_correction_capture');
    $this->actingAs($user)->postJson('/api/cic/submissions', [...$body, 'capture_id' => $this->simpleCapture(CicCapture::KIND_CORRECTION, '2026-09-30')->id])
        ->assertUnprocessable()->assertJsonPath('code', 'reference_date_mismatch');
});

it('needs a reason and the corrected file for a correction', function () {
    [$original, $correction] = cicCorrectionSetup($this);

    $this->actingAs($this->cicAll())->postJson('/api/cic/submissions', ['capture_id' => $correction->id, 'submission_type' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors([
            'corrects_submission_id' => 'Choose the file this correction corrects.',
            'correction_reason' => 'Give the reason for the correction.',
        ]);
});

it('refuses a correction that changes nothing', function () {
    $capture = $this->simpleCapture();
    $original = CicSubmission::factory()->submitted()->create(['capture_id' => $capture->id, 'reference_date' => '2026-10-31']);

    $this->actingAs($this->cicAll())->postJson('/api/cic/submissions', [
        'capture_id' => $this->simpleCapture(CicCapture::KIND_CORRECTION, corrects: $capture)->id, 'submission_type' => 1,
        'corrects_submission_id' => $original->id, 'correction_reason' => 'Wrong birthdate.',
    ])->assertUnprocessable()->assertJsonPath('code', 'nothing_to_correct');
});

it('refuses a correction capture that corrects a different capture from the corrected file\'s', function () {
    [$original, $correction] = cicCorrectionSetup($this);
    $user = $this->cicAll();
    $body = ['submission_type' => 1, 'corrects_submission_id' => $original->id, 'correction_reason' => 'Wrong birthdate.'];
    $otherCapture = $this->simpleCapture(CicCapture::KIND_TEST);
    $unlinked = $this->captureWith([['ID', 'BRW-00000001', ['ID5' => 'BRW-00000001', 'ID9' => '03011990']]], CicCapture::KIND_CORRECTION);
    $elsewhere = $this->captureWith([['ID', 'BRW-00000001', ['ID5' => 'BRW-00000001', 'ID9' => '03011990']]], CicCapture::KIND_CORRECTION, corrects: $otherCapture);

    foreach ([$unlinked, $elsewhere] as $capture) {
        $this->actingAs($user)->postJson('/api/cic/submissions', [...$body, 'capture_id' => $capture->id])
            ->assertUnprocessable()->assertJsonPath('code', 'capture_mismatch');
    }

    // A correction of the correction re-reads the same root capture.
    $first = CicSubmission::factory()->submitted()->correcting($original)->create(['capture_id' => $correction->id, 'reference_date' => '2026-10-31'])->id;
    $again = $this->captureWith([['ID', 'BRW-00000001', ['ID5' => 'BRW-00000001', 'ID9' => '04011990']]], CicCapture::KIND_CORRECTION, corrects: $original->capture);

    $this->actingAs($user)->postJson('/api/cic/submissions', [...$body, 'corrects_submission_id' => $first, 'capture_id' => $again->id])
        ->assertCreated()->assertJsonPath('data.correction.corrects.id', $first);
});

it('serves the pre-encryption review with the key and no personal data', function () {
    $key = CicPublicKey::factory()->active()->create();
    $submission = CicSubmission::factory()->create(['capture_id' => $this->simpleCapture()->id, 'reference_date' => '2026-10-31']);
    $viewer = $this->cicUser([CicAccess::VIEW]);

    $this->actingAs($viewer)->getJson("/api/cic/submissions/{$submission->id}")
        ->assertOk()
        ->assertJsonPath('data.reference', sprintf('CIC-%06d', $submission->id))
        ->assertJsonPath('data.format_version', '1.0')
        ->assertJsonPath('data.counts_by_type.ID', 1)
        ->assertJsonPath('data.counts_by_type.CI', 1)
        ->assertJsonPath('data.validation.errors', 0)
        ->assertJsonPath('data.active_key.fingerprint', $key->fingerprint)
        ->assertJsonPath('data.active_key.usable', true)
        ->assertJsonPath('data.encryption.output_extension', '.zip.gpg')
        ->assertJsonPath('data.actions.encrypt', false)
        ->assertJsonPath('data.actions.download', false)
        ->assertJsonStructure(['data' => ['id', 'filename', 'zip_filename', 'txt_sha256', 'capture' => ['id', 'kind', 'checksum'], 'history', 'corrections']]);
});

it('reads an expired key as unusable on the review', function () {
    CicPublicKey::factory()->active()->expired()->create();
    $submission = CicSubmission::factory()->create(['capture_id' => $this->simpleCapture()->id, 'reference_date' => '2026-10-31']);

    $this->actingAs($this->cicAll())->getJson("/api/cic/submissions/{$submission->id}")
        ->assertOk()
        ->assertJsonPath('data.active_key.expired', true)
        ->assertJsonPath('data.active_key.usable', false)
        ->assertJsonPath('data.actions.encrypt', false);
});

it('answers 404 for a file that does not exist', function () {
    $this->actingAs($this->cicAll())->getJson('/api/cic/submissions/999999')->assertNotFound();
});

it('lists the history, newest first, filtered by status and reference date', function () {
    $october = $this->simpleCapture();
    $september = $this->simpleCapture(CicCapture::KIND_MONTH_END, '2026-09-30');
    $old = CicSubmission::factory()->submitted()->create(['capture_id' => $september->id, 'reference_date' => '2026-09-30', 'generated_at' => now()->subMonth()]);
    $new = CicSubmission::factory()->create(['capture_id' => $october->id, 'reference_date' => '2026-10-31']);
    $user = $this->cicUser([CicAccess::VIEW]);

    $this->actingAs($user)->getJson('/api/cic/submissions')
        ->assertOk()->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.id', $new->id)->assertJsonPath('data.1.id', $old->id)
        ->assertJsonPath('data.1.status_label', 'Submitted to CIC');
    $this->actingAs($user)->getJson('/api/cic/submissions?status=submitted')
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $old->id);
    $this->actingAs($user)->getJson('/api/cic/submissions?reference_date=2026-10-31&per_page=1')
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('meta.per_page', 1)->assertJsonPath('data.0.id', $new->id);
    $this->actingAs($user)->getJson('/api/cic/submissions?reference_date=31/10/2026')
        ->assertUnprocessable()->assertJsonValidationErrors(['reference_date' => 'The reference date is a date (YYYY-MM-DD).']);
    $this->actingAs($this->cicUser([CicAccess::SETTINGS]))->getJson('/api/cic/submissions')->assertForbidden();
});

it('refuses a second file of the same name in the same second', function () {
    $this->freezeSecond();
    $capture = $this->simpleCapture();
    $user = $this->cicAll();

    $this->actingAs($user)->postJson('/api/cic/submissions', ['capture_id' => $capture->id, 'submission_type' => 0])->assertCreated();
    $this->actingAs($user)->postJson('/api/cic/submissions', ['capture_id' => $capture->id, 'submission_type' => 0])
        ->assertConflict()->assertJsonPath('code', 'filename_taken');

    expect(CicSubmission::query()->count())->toBe(1);
});
