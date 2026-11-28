<?php

use App\Models\Borrower;
use App\Models\CicSubmission;
use App\Models\Document;
use App\Services\Cic\CicAccess;
use App\Services\Cic\CicValidator;
use App\Services\Cic\Contracts\ValidatesCicCapture;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Cic\EndToEnd\CicEndToEndHelpers;
use Tests\TestCase;

uses(TestCase::class, CicEndToEndHelpers::class);

/*
 * CIC from start to finish with the real pieces: the settings endpoints, the
 * records builder and capture, the real validator binding, the file builder
 * and a real GnuPG. A member with a released loan on a mapped product, the
 * provider code, and a key pair made in the test (uploaded, then verified by
 * a second person); a test capture, a file, its pre-encryption review,
 * encryption, decryption with the test's secret key, download, submission
 * and CIC's answer; then a correction after the member's name changed.
 */

beforeEach(function () {
    $this->cicOn();
    config(['cic.test_captures' => true]);
    Storage::fake('private');
});

afterEach(function () {
    $this->tearDownTestKeys();
});

it('captures, files, encrypts, submits, acknowledges and corrects a month with the real pieces', function () {
    expect(app(ValidatesCicCapture::class))->toBeInstanceOf(CicValidator::class);

    $member = $this->cicMember();
    Document::query()->forceCreate(['documentable_type' => Borrower::class, 'documentable_id' => $member->id, 'type' => 'valid_id', 'label' => 'tin_id', 'id_number' => '123-456-789', 'file_path' => 'tin', 'original_filename' => 'tin.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 1]);
    $loan = $this->cicLoan($member);

    $settings = $this->cicUser([CicAccess::VIEW, CicAccess::SETTINGS]);
    $checker = $this->cicUser([CicAccess::VIEW, CicAccess::SETTINGS]);
    $preparer = $this->cicUser([CicAccess::VIEW, CicAccess::PREPARE, CicAccess::SUBMIT, 'borrowers:view']);
    $reviewer = $this->cicUser([CicAccess::VIEW, CicAccess::REVIEW]);

    // The provider code and CIC's key, uploaded by one person and verified by another.
    $this->actingAs($settings)->putJson('/api/cic/settings', ['provider_code' => 'LENDY001'])->assertOk();
    $key = $this->makeTestKey();
    $keyId = $this->actingAs($settings)->postJson('/api/cic/public-keys', ['armored' => $key['armored']])
        ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
    $this->actingAs($checker)->postJson("/api/cic/public-keys/{$keyId}/verify", ['fingerprint' => $key['fingerprint']])
        ->assertOk()->assertJsonPath('data.status', 'active');

    // A test capture, and a file made from it through the real validator.
    $capture = $this->actingAs($preparer)->postJson('/api/cic/captures', ['kind' => 'test'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'complete')
        ->assertJsonPath('data.reference_date', '2026-11-30')
        ->assertJsonPath('data.subject_count', 1)
        ->assertJsonPath('data.contract_count', 1)
        ->json('data');

    $this->actingAs($preparer)->getJson("/api/cic/records/CI/{$loan->loan_account_number}?capture_id={$capture['id']}")
        ->assertOk()->assertJsonPath('data.errors', 0);

    $submissionId = $this->actingAs($preparer)->postJson('/api/cic/submissions', ['capture_id' => $capture['id'], 'submission_type' => 0, 'confirm_warnings' => true])
        ->assertCreated()
        ->assertJsonPath('data.status', 'generated')
        ->assertJsonPath('data.record_count', 2)
        ->assertJsonPath('data.counts_by_type.ID', 1)
        ->assertJsonPath('data.counts_by_type.CI', 1)
        ->assertJsonPath('data.validation.errors', 0)
        ->json('data.id');

    // The pre-encryption review names the active key and holds no member values.
    $review = $this->actingAs($preparer)->getJson("/api/cic/submissions/{$submissionId}")
        ->assertOk()
        ->assertJsonPath('data.provider_code', 'LENDY001')
        ->assertJsonPath('data.active_key.id', $keyId)
        ->assertJsonPath('data.active_key.fingerprint', $key['fingerprint'])
        ->assertJsonPath('data.active_key.usable', true)
        ->assertJsonPath('data.actions.encrypt', true);
    expect($review->getContent())->not->toContain('Maria')->not->toContain('123-456-789');

    $this->actingAs($preparer)->postJson("/api/cic/submissions/{$submissionId}/encrypt", ['confirm' => true])
        ->assertOk()->assertJsonPath('data.status', 'encrypted')->assertJsonPath('data.key_fingerprint', $key['fingerprint']);
    $submission = CicSubmission::query()->findOrFail($submissionId);

    // Decrypted with the test's secret key: a ZIP holding the TXT, every line ended by CRLF.
    $zipPath = $key['home'].'/e2e.zip';
    file_put_contents($zipPath, $this->decryptWith($key['home'], Storage::disk('private')->get($submission->gpg_path)));
    $zip = new ZipArchive;
    expect($zip->open($zipPath))->toBeTrue()->and($zip->getNameIndex(0))->toBe($submission->filename);
    $txt = (string) $zip->getFromIndex(0);
    $zip->close();

    $lines = explode("\r\n", $txt);
    expect($submission->filename)->toBe('LENDY001_CSDF_20261130235800.txt')
        ->and(hash('sha256', $txt))->toBe($submission->txt_sha256)
        ->and(str_ends_with($txt, "\r\n"))->toBeTrue()
        ->and(substr_count($txt, "\n"))->toBe(substr_count($txt, "\r\n"))
        ->and(substr_count($txt, "\r"))->toBe(substr_count($txt, "\r\n"))
        ->and($lines)->toHaveCount(5)
        ->and($lines[0])->toBe('HD|LENDY001|30112026|1.0|0|')
        ->and($lines[1])->toStartWith('ID|LENDY001|')->toContain("|{$member->borrower_code}|")->toContain('|Maria|')
        ->and($lines[2])->toStartWith('CI|LENDY001|')->toContain("|{$loan->loan_account_number}|")
        ->and($lines[3])->toBe('FT|LENDY001|30112026|2')
        ->and($lines[4])->toBe('');

    // Download (the encrypted file only), mark submitted, then CIC's answer by someone else.
    $download = $this->actingAs($preparer)->get("/api/cic/submissions/{$submissionId}/download", ['Accept' => 'application/json'])->assertOk();
    expect($download->streamedContent())->toBe(Storage::disk('private')->get($submission->gpg_path))
        ->and($submission->fresh()->download_count)->toBe(1);

    $this->actingAs($preparer)->postJson("/api/cic/submissions/{$submissionId}/mark-submitted")
        ->assertOk()->assertJsonPath('data.status', 'submitted');

    $preparer->givePermissionTo(CicAccess::REVIEW);
    $this->actingAs($preparer)->postJson("/api/cic/submissions/{$submissionId}/acknowledgement", ['status' => 'accepted', 'notes' => 'Self'])
        ->assertForbidden()->assertJsonPath('code', 'generator_cannot_review');
    $this->actingAs($reviewer)->postJson("/api/cic/submissions/{$submissionId}/acknowledgement", ['status' => 'accepted', 'notes' => 'Loaded by CIC.'])
        ->assertOk()->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.acknowledged_by.id', $reviewer->id);

    // A correction: the member's name changed, so a correction capture re-reads the subject and keeps the contract.
    $member->update(['first_name' => 'Mariana']);
    Carbon::setTestNow('2026-12-01 09:15:00');

    $correction = $this->actingAs($preparer)->postJson('/api/cic/captures', ['kind' => 'correction', 'corrects_capture_id' => $capture['id']])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'correction')
        ->assertJsonPath('data.reference_date', '2026-11-30')
        ->assertJsonPath('data.corrects_capture_id', $capture['id'])
        ->json('data');

    $this->actingAs($preparer)->postJson('/api/cic/submissions', ['capture_id' => $correction['id'], 'submission_type' => 1, 'confirm_warnings' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['corrects_submission_id', 'correction_reason']);

    $corrected = $this->actingAs($preparer)->postJson('/api/cic/submissions', [
        'capture_id' => $correction['id'], 'submission_type' => 1, 'confirm_warnings' => true,
        'corrects_submission_id' => $submissionId, 'correction_reason' => 'The member\'s first name was misspelt.',
    ])->assertCreated()
        ->assertJsonPath('data.submission_type', 1)
        ->assertJsonPath('data.corrects_submission_id', $submissionId)
        ->assertJsonPath('data.filename', 'LENDY001_CSDF_20261201091500.txt')
        ->assertJsonPath('data.correction.reason', 'The member\'s first name was misspelt.')
        ->assertJsonPath('data.correction.affected_count', 1)
        ->assertJsonPath('data.correction.affected_records', [
            ['record_type' => 'ID', 'record_key' => $member->borrower_code, 'change' => 'changed'],
        ]);
    expect($corrected->getContent())->not->toContain('Mariana');

    $this->actingAs($preparer)->getJson("/api/cic/submissions/{$submissionId}")
        ->assertOk()->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.corrections.0.id', $corrected->json('data.id'));
});
