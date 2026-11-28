<?php

use App\Exceptions\CicRecordIsFrozenException;
use App\Models\AuditLog;
use App\Models\CicCapture;
use App\Models\CicSubmission;
use App\Services\Cic\CicAccess;
use App\Services\Cic\Submissions\CicSubmissionPresenter;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Cic\Submissions\CicSubmissionsTestHelpers;
use Tests\TestCase;

uses(TestCase::class, CicSubmissionsTestHelpers::class);

/*
 * After encryption: the encrypted download (only the .zip.gpg, counted and
 * audited every time), marking a file submitted, and recording CIC's answer
 * (never by the file's generator, from submitted only).
 */

beforeEach(function () {
    $this->setUpCic();
    Storage::fake('private');
});

afterEach(function () {
    $this->tearDownTestKeys();
});

/** An encrypted file whose .zip.gpg is on the (fake) private disk. */
function cicEncrypted(object $test, string $state = 'encrypted'): CicSubmission
{
    // File names are unique, so each file made here is a second later.
    static $second = 10;
    $base = 'TEST1234_CSDF_202611251430'.str_pad((string) $second++, 2, '0', STR_PAD_LEFT);
    $bytes = "\x85\x01\x0c".random_bytes(64);
    $submission = CicSubmission::factory()->{$state}()->create([
        'capture_id' => $test->simpleCapture(CicCapture::KIND_TEST)->id,
        'reference_date' => '2026-10-31',
        'filename' => "{$base}.txt",
        'gpg_path' => 'cic/submissions/'.bin2hex(random_bytes(4))."/{$base}.zip.gpg",
        'gpg_sha256' => hash('sha256', $bytes),
    ]);
    Storage::disk('private')->put($submission->gpg_path, $bytes);

    return $submission;
}

it('streams only the encrypted file, counting and auditing every download', function () {
    $submission = cicEncrypted($this);
    $user = $this->cicUser([CicAccess::SUBMIT]);

    $first = $this->actingAs($user)->get("/api/cic/submissions/{$submission->id}/download", ['Accept' => 'application/json'])->assertOk();
    expect($first->streamedContent())->toBe(Storage::disk('private')->get($submission->gpg_path))
        ->and($first->headers->get('Content-Disposition'))->toContain(str_replace('.txt', '.zip.gpg', $submission->filename))
        ->and($first->headers->get('Content-Type'))->toBe('application/pgp-encrypted');

    $this->actingAs($user)->get("/api/cic/submissions/{$submission->id}/download", ['Accept' => 'application/json'])->assertOk();

    expect($submission->fresh()->download_count)->toBe(2)
        ->and(AuditLog::query()->where('auditable_type', CicSubmission::class)->where('auditable_id', $submission->id)->where('action', 'cic_file_downloaded')->pluck('new_values')->pluck('download_count')->all())
        ->toBe([1, 2]);
});

it('downloads a submitted, accepted or rejected file too', function () {
    $submission = cicEncrypted($this, 'submitted');

    $this->actingAs($this->cicUser([CicAccess::SUBMIT]))->get("/api/cic/submissions/{$submission->id}/download", ['Accept' => 'application/json'])->assertOk();
});

it('has nothing to download before encryption, and never a plain file', function () {
    $submission = CicSubmission::factory()->create(['capture_id' => $this->simpleCapture()->id, 'reference_date' => '2026-10-31']);

    $this->actingAs($this->cicUser([CicAccess::SUBMIT]))->getJson("/api/cic/submissions/{$submission->id}/download")
        ->assertUnprocessable()->assertJsonPath('code', 'not_encrypted');

    expect($submission->fresh()->download_count)->toBe(0);
});

it('refuses a stored file that no longer matches its checksum, or is missing', function () {
    $submission = cicEncrypted($this);
    $user = $this->cicUser([CicAccess::SUBMIT]);
    Storage::disk('private')->put($submission->gpg_path, 'tampered');

    $this->actingAs($user)->getJson("/api/cic/submissions/{$submission->id}/download")->assertConflict()->assertJsonPath('code', 'file_changed');

    Storage::disk('private')->delete($submission->gpg_path);
    $this->actingAs($user)->getJson("/api/cic/submissions/{$submission->id}/download")->assertConflict()->assertJsonPath('code', 'file_missing');

    expect($submission->fresh()->download_count)->toBe(0);
});

it('needs cic:submit to download', function () {
    $submission = cicEncrypted($this);

    $this->actingAs($this->cicUser([CicAccess::VIEW, CicAccess::REVIEW]))->getJson("/api/cic/submissions/{$submission->id}/download")->assertForbidden();
});

it('marks an encrypted file submitted, once', function () {
    $submission = cicEncrypted($this);
    $user = $this->cicUser([CicAccess::SUBMIT]);

    $this->actingAs($user)->postJson("/api/cic/submissions/{$submission->id}/mark-submitted")
        ->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.submitted_by.id', $user->id)
        ->assertJsonPath('data.history.2.status', 'submitted');

    $this->actingAs($user)->postJson("/api/cic/submissions/{$submission->id}/mark-submitted")
        ->assertUnprocessable()->assertJsonPath('code', 'not_encrypted');

    expect(AuditLog::query()->where('auditable_id', $submission->id)->where('action', 'cic_file_submitted')->count())->toBe(1);
});

it('refuses to mark a file submitted before it is encrypted, or without cic:submit', function () {
    $generated = CicSubmission::factory()->create(['capture_id' => $this->simpleCapture()->id, 'reference_date' => '2026-10-31']);

    $this->actingAs($this->cicUser([CicAccess::SUBMIT]))->postJson("/api/cic/submissions/{$generated->id}/mark-submitted")
        ->assertUnprocessable()->assertJsonPath('code', 'not_encrypted');
    $this->actingAs($this->cicUser([CicAccess::PREPARE]))->postJson("/api/cic/submissions/{$generated->id}/mark-submitted")
        ->assertForbidden();
});

it('records CIC\'s answer by a reviewer who did not generate the file', function () {
    $submission = cicEncrypted($this, 'submitted');
    $reviewer = $this->cicUser([CicAccess::REVIEW]);

    $this->actingAs($reviewer)->postJson("/api/cic/submissions/{$submission->id}/acknowledgement", ['status' => 'accepted', 'notes' => 'Batch 12 loaded.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'accepted')
        ->assertJsonPath('data.status_label', 'Accepted by CIC')
        ->assertJsonPath('data.acknowledged_by.id', $reviewer->id)
        ->assertJsonPath('data.acknowledgement_notes', 'Batch 12 loaded.')
        ->assertJsonPath('data.history.3.status', 'accepted');

    $audit = AuditLog::query()->where('auditable_id', $submission->id)->where('action', 'cic_file_acknowledged')->sole();
    expect($audit->new_values)->toBe(['status' => 'accepted', 'notes_length' => 16, 'cic_reference_no' => null]);

    $this->actingAs($reviewer)->postJson("/api/cic/submissions/{$submission->id}/acknowledgement", ['status' => 'rejected', 'notes' => 'Again.'])
        ->assertUnprocessable()->assertJsonPath('code', 'not_submitted');
});

it('records CIC\'s own reference number with its answer, shows it on the row and detail, and audits it', function () {
    $submission = cicEncrypted($this, 'submitted');
    $reviewer = $this->cicUser([CicAccess::VIEW, CicAccess::REVIEW]);

    $this->actingAs($reviewer)->postJson("/api/cic/submissions/{$submission->id}/acknowledgement", ['status' => 'accepted', 'cic_reference_no' => '=cmd|x'])
        ->assertUnprocessable()->assertJsonValidationErrors(['cic_reference_no' => 'CIC\'s reference number takes letters, digits, spaces and - _ / . # : only, starting with a letter or digit.']);
    $this->actingAs($reviewer)->postJson("/api/cic/submissions/{$submission->id}/acknowledgement", ['status' => 'accepted', 'cic_reference_no' => str_repeat('A', 61)])
        ->assertUnprocessable()->assertJsonValidationErrors(['cic_reference_no' => 'CIC\'s reference number takes up to 60 characters.']);

    $this->actingAs($reviewer)->postJson("/api/cic/submissions/{$submission->id}/acknowledgement", ['status' => 'accepted', 'cic_reference_no' => '  CSDF-2026/11   0042 '])
        ->assertOk()->assertJsonPath('data.cic_reference_no', 'CSDF-2026/11 0042');

    $this->actingAs($reviewer)->getJson('/api/cic/submissions')->assertOk()
        ->assertJsonPath('data.0.id', $submission->id)
        ->assertJsonPath('data.0.cic_reference_no', 'CSDF-2026/11 0042');

    $audit = AuditLog::query()->where('auditable_id', $submission->id)->where('action', 'cic_file_acknowledged')->sole();
    expect($audit->new_values)->toBe(['status' => 'accepted', 'notes_length' => 0, 'cic_reference_no' => 'CSDF-2026/11 0042'])
        ->and($audit->description)->toEndWith('(CIC reference CSDF-2026/11 0042)');

    expect(fn () => $submission->fresh()->forceFill(['cic_reference_no' => 'OTHER'])->save())->toThrow(CicRecordIsFrozenException::class);

    $blank = cicEncrypted($this, 'submitted');
    $this->actingAs($reviewer)->postJson("/api/cic/submissions/{$blank->id}/acknowledgement", ['status' => 'rejected', 'notes' => 'Bad CI33.', 'cic_reference_no' => '   '])
        ->assertOk()->assertJsonPath('data.cic_reference_no', null);
});

it('never lets the generator record CIC\'s answer', function () {
    $submission = cicEncrypted($this, 'submitted');
    $generator = $submission->generator;
    $generator->givePermissionTo([CicAccess::REVIEW, 'branches:view_all']);

    $this->actingAs($generator)->postJson("/api/cic/submissions/{$submission->id}/acknowledgement", ['status' => 'accepted'])
        ->assertForbidden()
        ->assertJsonPath('message', 'The person who generated a file can\'t record CIC\'s answer to it. Ask another reviewer.');

    expect($submission->fresh()->status)->toBe('submitted');
});

it('needs notes for a rejection, and a submitted file', function () {
    $submission = cicEncrypted($this, 'submitted');
    $reviewer = $this->cicUser([CicAccess::REVIEW]);

    $this->actingAs($reviewer)->postJson("/api/cic/submissions/{$submission->id}/acknowledgement", ['status' => 'rejected'])
        ->assertUnprocessable()->assertJsonValidationErrors(['notes' => 'Give CIC\'s reason for rejecting the file.']);
    $this->actingAs($reviewer)->postJson("/api/cic/submissions/{$submission->id}/acknowledgement", ['status' => 'pending'])
        ->assertUnprocessable()->assertJsonValidationErrors(['status' => 'CIC\'s answer is accepted or rejected.']);
    $this->actingAs($reviewer)->postJson("/api/cic/submissions/{$submission->id}/acknowledgement", ['status' => 'rejected', 'notes' => 'Wrong format of CI33.'])
        ->assertOk()->assertJsonPath('data.status', 'rejected');

    $encrypted = cicEncrypted($this);
    $this->actingAs($reviewer)->postJson("/api/cic/submissions/{$encrypted->id}/acknowledgement", ['status' => 'accepted'])
        ->assertUnprocessable()->assertJsonPath('code', 'not_submitted');
    $this->actingAs($this->cicUser([CicAccess::SUBMIT]))->postJson("/api/cic/submissions/{$submission->id}/acknowledgement", ['status' => 'accepted'])
        ->assertForbidden();
});

it('gives each list row the caller\'s actions, the same object as the detail', function () {
    $this->activateTestKey($this->makeTestKey());
    $generated = CicSubmission::factory()->create(['capture_id' => $this->simpleCapture(CicCapture::KIND_TEST)->id, 'reference_date' => '2026-10-31']);
    $encrypted = cicEncrypted($this);
    $submitted = cicEncrypted($this, 'submitted');

    foreach ([$this->cicAll(), $this->cicUser([CicAccess::VIEW]), $submitted->generator->givePermissionTo([...CicAccess::PERMISSIONS, 'branches:view_all'])] as $user) {
        $rows = collect($this->actingAs($user)->getJson('/api/cic/submissions')->assertOk()->json('data'))->keyBy('id');

        foreach ([$generated, $encrypted, $submitted] as $submission) {
            expect($rows[$submission->id]['actions'])
                ->toBe($this->actingAs($user)->getJson("/api/cic/submissions/{$submission->id}")->assertOk()->json('data.actions'));
        }
    }

    $all = collect($this->actingAs($this->cicAll())->getJson('/api/cic/submissions')->json('data'))->keyBy('id');
    expect($all[$generated->id]['actions'])->toBe(['encrypt' => true, 'download' => false, 'mark_submitted' => false, 'acknowledge' => false, 'correct' => false])
        ->and($all[$encrypted->id]['actions'])->toBe(['encrypt' => false, 'download' => true, 'mark_submitted' => true, 'acknowledge' => false, 'correct' => false])
        ->and($all[$submitted->id]['actions'])->toBe(['encrypt' => false, 'download' => true, 'mark_submitted' => false, 'acknowledge' => true, 'correct' => true]);
});

it('offers no encryption on a list row whose capture has errors', function () {
    $this->activateTestKey($this->makeTestKey());
    $this->fakeIssues([['record_type' => 'ID', 'record_key' => 'BRW-00000001', 'field_id' => 'ID7', 'severity' => 'error', 'code' => 'MISSING_MANDATORY', 'message' => 'x']]);
    $generated = CicSubmission::factory()->create(['capture_id' => $this->simpleCapture(CicCapture::KIND_TEST)->id, 'reference_date' => '2026-10-31']);

    $this->actingAs($this->cicAll())->getJson('/api/cic/submissions')->assertOk()
        ->assertJsonPath('data.0.id', $generated->id)
        ->assertJsonPath('data.0.actions.encrypt', false)
        ->assertJsonPath('data.0.actions.download', false);
});

it('writes CIC in capitals in refusals and audit text that name a status', function () {
    $submitted = cicEncrypted($this, 'submitted');
    $reviewer = $this->cicUser([CicAccess::REVIEW]);

    $this->actingAs($this->cicUser([CicAccess::SUBMIT]))->postJson("/api/cic/submissions/{$submitted->id}/mark-submitted")
        ->assertUnprocessable()->assertJsonPath('message', 'Only an encrypted file can be marked submitted; this one is submitted to CIC.');

    $this->actingAs($reviewer)->postJson("/api/cic/submissions/{$submitted->id}/acknowledgement", ['status' => 'accepted'])->assertOk();
    $this->actingAs($reviewer)->postJson("/api/cic/submissions/{$submitted->id}/acknowledgement", ['status' => 'accepted'])
        ->assertUnprocessable()->assertJsonPath('message', 'CIC\'s answer can be recorded only for a file marked submitted; this one is accepted by CIC.');

    expect(AuditLog::query()->where('auditable_id', $submitted->id)->where('action', 'cic_file_acknowledged')->sole()->description)
        ->toEndWith(': accepted by CIC')
        ->and(CicSubmissionPresenter::statusWords('generated'))->toBe('generated');
});
