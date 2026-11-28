<?php

use App\Models\AuditLog;
use App\Models\CicPublicKey;
use App\Models\CicSetting;
use App\Models\CicSubmission;
use App\Models\User;
use App\Services\Cic\CicAccess;
use App\Services\Cic\File\CicFileBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Cic\Submissions\CicSubmissionsTestHelpers;
use Tests\TestCase;

uses(TestCase::class, CicSubmissionsTestHelpers::class);

/*
 * POST /cic/submissions/{id}/encrypt, end to end with a real GnuPG: a key pair
 * made in the test (never committed) is the active verified key; the file is
 * rebuilt, zipped and encrypted in a temporary folder that is removed, only
 * the .zip.gpg is stored, and the test's secret key decrypts it to a ZIP
 * holding exactly the TXT. Refusals: no confirmation, no usable key, no gpg,
 * a changed capture or settings, a key whose fingerprint doesn't match.
 */

beforeEach(function () {
    $this->setUpCic();
    Storage::fake('private');
});

afterEach(function () {
    $this->tearDownTestKeys();
});

/** A generated file of a two-record capture, made through the endpoint. */
function cicGenerated(object $test, User $user): CicSubmission
{
    $capture = $test->captureWith([
        ['ID', 'BRW-00000001', ['ID5' => 'BRW-00000001', 'ID7' => 'DELA CRUZ']],
        ['CI', 'LN-000001'],
    ]);

    $id = $test->actingAs($user)->postJson('/api/cic/submissions', ['capture_id' => $capture->id, 'submission_type' => 0])
        ->assertCreated()->json('data.id');

    return CicSubmission::query()->findOrFail($id);
}

it('encrypts to the active key, stores only the .zip.gpg, and decrypts to a ZIP holding exactly the TXT', function () {
    $key = $this->makeTestKey();
    $publicKey = $this->activateTestKey($key);
    $user = $this->cicAll();
    $submission = cicGenerated($this, $user);
    $base = substr($submission->filename, 0, -4);

    $response = $this->actingAs($user)->postJson("/api/cic/submissions/{$submission->id}/encrypt", ['confirm' => true])
        ->assertOk()
        ->assertJsonPath('data.status', 'encrypted')
        ->assertJsonPath('data.encrypted_filename', "{$base}.zip.gpg")
        ->assertJsonPath('data.key_fingerprint', $key['fingerprint'])
        ->assertJsonPath('data.encrypted_with.fingerprint', $key['fingerprint'])
        ->assertJsonPath('data.encrypted_by.id', $user->id)
        ->assertJsonPath('data.history.1.status', 'encrypted')
        ->assertJsonPath('data.actions.download', true);

    $submission->refresh();
    expect($submission->gpg_path)->toBe("cic/submissions/{$submission->id}/{$base}.zip.gpg")
        ->and(Storage::disk('private')->allFiles())->toBe([$submission->gpg_path])
        ->and($this->leftoverEncryptionFolders())->toBe([])
        ->and($response->getContent())->not->toContain('DELA CRUZ');

    $encrypted = Storage::disk('private')->get($submission->gpg_path);
    expect(hash('sha256', $encrypted))->toBe($submission->gpg_sha256)
        ->and($encrypted)->not->toContain('DELA CRUZ');

    $zipBytes = $this->decryptWith($key['home'], $encrypted);
    expect(hash('sha256', $zipBytes))->toBe($submission->zip_sha256);

    $zipPath = $key['home'].'/check.zip';
    file_put_contents($zipPath, $zipBytes);
    $zip = new ZipArchive;
    expect($zip->open($zipPath))->toBeTrue()
        ->and($zip->numFiles)->toBe(1)
        ->and($zip->getNameIndex(0))->toBe($submission->filename);
    $txt = $zip->getFromIndex(0);
    $zip->close();

    expect(hash('sha256', $txt))->toBe($submission->txt_sha256)
        ->and($txt)->toBe(app(CicFileBuilder::class)->forSubmission($submission)->contents)
        ->and($txt)->toStartWith('HD|TEST1234|31102026|1.0|0|');

    $audit = AuditLog::query()->where('auditable_type', CicSubmission::class)->where('auditable_id', $submission->id)->where('action', 'cic_file_encrypted')->sole();
    expect($audit->new_values['key_fingerprint'])->toBe($key['fingerprint'])
        ->and($audit->new_values['key_id'])->toBe($publicKey->id)
        ->and($audit->description)->toContain("{$base}.zip.gpg");

    $this->actingAs($user)->postJson("/api/cic/submissions/{$submission->id}/encrypt", ['confirm' => true])
        ->assertUnprocessable()->assertJsonPath('code', 'not_generated');
});

it('needs an explicit confirmation and cic:submit', function () {
    $user = $this->cicAll();
    $submission = cicGenerated($this, $user);

    $this->actingAs($user)->postJson("/api/cic/submissions/{$submission->id}/encrypt", [])
        ->assertUnprocessable()->assertJsonValidationErrors(['confirm' => 'Confirm that you reviewed the file before it is encrypted.']);
    $this->actingAs($user)->postJson("/api/cic/submissions/{$submission->id}/encrypt", ['confirm' => false])
        ->assertUnprocessable()->assertJsonValidationErrors('confirm');
    $this->actingAs($this->cicUser([CicAccess::VIEW, CicAccess::PREPARE]))->postJson("/api/cic/submissions/{$submission->id}/encrypt", ['confirm' => true])
        ->assertForbidden()->assertJsonPath('message', 'You don\'t have access to submit CIC files.');
});

it('refuses without a usable key, saving nothing', function (string $state, string $message) {
    $user = $this->cicAll();
    $submission = cicGenerated($this, $user);

    match ($state) {
        'none' => null,
        'pending' => CicPublicKey::factory()->create(),
        'unverified' => CicPublicKey::factory()->active()->create(['verified_at' => null, 'verified_by' => null]),
        'expired' => CicPublicKey::factory()->active()->expired()->create(),
    };

    $this->actingAs($user)->postJson("/api/cic/submissions/{$submission->id}/encrypt", ['confirm' => true])
        ->assertUnprocessable()->assertJsonPath('code', 'key_unusable')->assertJsonPath('message', fn (string $text) => str_contains($text, $message));

    expect($submission->fresh()->status)->toBe('generated')
        ->and($submission->fresh()->gpg_path)->toBeNull()
        ->and(Storage::disk('private')->allFiles())->toBe([]);
})->with([
    'no key' => ['none', 'No CIC public key is active'],
    'only a pending key' => ['pending', 'No CIC public key is active'],
    'an unverified key' => ['unverified', 'hasn\'t been verified'],
    'an expired key' => ['expired', 'has expired'],
]);

it('refuses when gpg is missing, never falling back', function () {
    $key = $this->makeTestKey();
    $this->activateTestKey($key);
    $user = $this->cicAll();
    $submission = cicGenerated($this, $user);
    config(['cic.gpg_binary' => '/nonexistent/gpg']);

    $this->actingAs($user)->postJson("/api/cic/submissions/{$submission->id}/encrypt", ['confirm' => true])
        ->assertUnprocessable()->assertJsonPath('code', 'encryption_failed')
        ->assertJsonPath('message', 'GnuPG isn\'t installed on this server, so the file can\'t be encrypted. Nothing was saved.');

    expect($submission->fresh()->status)->toBe('generated')
        ->and(Storage::disk('private')->allFiles())->toBe([]);
});

it('refuses with 409 when the capture or the rebuilt file differs from the generated one', function (string $change, string $code) {
    $key = $this->makeTestKey();
    $this->activateTestKey($key);
    $user = $this->cicAll();
    $submission = cicGenerated($this, $user);

    if ($change === 'records') {
        DB::table('cic_capture_records')->where('capture_id', $submission->capture_id)->where('record_type', 'CI')
            ->update(['values' => json_encode(['CI5' => 'BRW-00000009'])]);
    } else {
        CicSetting::query()->orderBy('id')->firstOrFail()->forceFill(['provider_code' => 'OTHER123'])->save();
    }

    $this->actingAs($user)->postJson("/api/cic/submissions/{$submission->id}/encrypt", ['confirm' => true])
        ->assertConflict()->assertJsonPath('code', $code);

    expect($submission->fresh()->status)->toBe('generated')
        ->and(Storage::disk('private')->allFiles())->toBe([])
        ->and($this->leftoverEncryptionFolders())->toBe([]);
})->with(['a changed capture' => ['records', 'capture_changed'], 'a changed provider code' => ['provider', 'file_changed']]);

it('refuses a key whose material does not match its recorded fingerprint', function () {
    $key = $this->makeTestKey();
    $this->activateTestKey($key, ['fingerprint' => str_repeat('A', 40)]);
    $user = $this->cicAll();
    $submission = cicGenerated($this, $user);

    $this->actingAs($user)->postJson("/api/cic/submissions/{$submission->id}/encrypt", ['confirm' => true])
        ->assertUnprocessable()->assertJsonPath('code', 'encryption_failed')
        ->assertJsonPath('message', fn (string $text) => str_contains($text, 'doesn\'t match its recorded fingerprint'));

    expect(Storage::disk('private')->allFiles())->toBe([])
        ->and($this->leftoverEncryptionFolders())->toBe([]);
});

it('refuses when the capture now has errors', function () {
    $key = $this->makeTestKey();
    $this->activateTestKey($key);
    $user = $this->cicAll();
    $submission = cicGenerated($this, $user);
    $this->fakeIssues([['record_type' => 'ID', 'record_key' => 'BRW-00000001', 'field_id' => 'ID7', 'severity' => 'error', 'code' => 'required', 'message' => 'Missing.']]);

    $this->actingAs($user)->postJson("/api/cic/submissions/{$submission->id}/encrypt", ['confirm' => true])
        ->assertUnprocessable()->assertJsonPath('code', 'validation_errors');
});
