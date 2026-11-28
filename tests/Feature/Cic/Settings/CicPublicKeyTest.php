<?php

use App\Models\AuditLog;
use App\Models\CicPublicKey;
use App\Services\Cic\CicAccess;
use App\Services\Cic\Settings\CicKeyInspector;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Cic\Settings\CicTestKeys;
use Tests\TestCase;

uses(TestCase::class);

/*
 * CIC's public keys: upload (pending), verification of the fingerprint by a
 * second settings holder (which retires the active key first), retirement
 * with a reason, the history without the armor, and an audit row per action
 * by fingerprint.
 */

beforeEach(function () {
    config(['cic.enabled' => true]);
    $this->uploader = CicTestKeys::staff([CicAccess::SETTINGS]);
    $this->checker = CicTestKeys::staff([CicAccess::SETTINGS]);
});

function cicUpload(object $test, string $armor, $user = null)
{
    return $test->actingAs($user ?? $test->uploader)->postJson('/api/cic/public-keys', ['armored' => $armor]);
}

it('stores an uploaded key as pending and audits it by fingerprint', function () {
    $fingerprint = CicTestKeys::fingerprint();

    cicUpload($this, CicTestKeys::valid())
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.fingerprint', $fingerprint)
        ->assertJsonPath('data.algorithm', 'EdDSA (ed25519)')
        ->assertJsonPath('data.user_ids', ['CIC Test cic <cic@example.invalid>'])
        ->assertJsonPath('data.uploaded_by.id', $this->uploader->id)
        ->assertJsonPath('data.can_verify', false)
        ->assertJsonMissingPath('data.armored');

    $key = CicPublicKey::query()->sole();
    $audit = AuditLog::query()->where('auditable_type', CicPublicKey::class)->sole();

    expect($key->armored)->toContain('BEGIN PGP PUBLIC KEY BLOCK')
        ->and($audit->action)->toBe('created')
        ->and($audit->user_id)->toBe($this->uploader->id)
        ->and($audit->description)->toContain($fingerprint)
        ->and($audit->new_values['fingerprint'])->toBe($fingerprint)
        ->and(json_encode($audit->new_values))->not->toContain('BEGIN PGP');
});

it('takes the key as an .asc file too', function () {
    $file = UploadedFile::fake()->createWithContent('cic.asc', CicTestKeys::valid());

    $this->actingAs($this->uploader)->post('/api/cic/public-keys', ['file' => $file], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.fingerprint', CicTestKeys::fingerprint());
});

it('refuses an unusable key in words on its field, and stores nothing', function () {
    cicUpload($this, CicTestKeys::secret())->assertUnprocessable()->assertJsonPath('errors.armored.0', CicKeyInspector::PRIVATE_KEY);
    cicUpload($this, CicTestKeys::expired())->assertUnprocessable()->assertJsonPath('errors.armored.0', CicKeyInspector::EXPIRED);
    cicUpload($this, 'not a key')->assertUnprocessable()->assertJsonPath('errors.armored.0', CicKeyInspector::NOT_A_KEY);
    cicUpload($this, str_repeat('A', CicKeyInspector::MAX_BYTES + 1))->assertUnprocessable()->assertJsonPath('errors.armored.0', CicKeyInspector::TOO_LARGE);
    $this->actingAs($this->uploader)->postJson('/api/cic/public-keys', [])->assertUnprocessable()->assertJsonValidationErrors('armored');

    expect(CicPublicKey::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('auditable_type', CicPublicKey::class)->count())->toBe(0);
});

it('answers 422 in words when the server has no GnuPG', function () {
    $armor = CicTestKeys::valid();
    config(['cic.gpg_binary' => '/nonexistent/gpg']);

    cicUpload($this, $armor)->assertUnprocessable()->assertJsonPath('errors.armored.0', CicKeyInspector::GPG_MISSING);
});

it('refuses the same key twice', function () {
    cicUpload($this, CicTestKeys::valid())->assertCreated();

    cicUpload($this, CicTestKeys::valid())->assertUnprocessable()
        ->assertJsonPath('errors.armored.0', 'This key was already uploaded (waiting for verification). Each key is uploaded once; its history stays in the list.');
});

it('lets only a different settings holder verify, with the fingerprint checked with CIC', function () {
    $id = cicUpload($this, CicTestKeys::valid())->json('data.id');
    $fingerprint = CicTestKeys::fingerprint();

    $this->actingAs($this->uploader)->postJson("/api/cic/public-keys/{$id}/verify", ['fingerprint' => $fingerprint])
        ->assertForbidden()
        ->assertJsonPath('message', 'A second person verifies a key: you uploaded this one, so someone else with access to CIC settings must check its fingerprint with CIC.');

    $this->actingAs($this->checker)->postJson("/api/cic/public-keys/{$id}/verify", ['fingerprint' => strrev($fingerprint)])
        ->assertUnprocessable()
        ->assertJsonPath('errors.fingerprint.0', 'The fingerprint you entered doesn\'t match this key. Check it with CIC again; if CIC\'s fingerprint is different, don\'t use this key.');

    $viewer = CicTestKeys::staff([CicAccess::VIEW]);
    $this->actingAs($viewer)->postJson("/api/cic/public-keys/{$id}/verify", ['fingerprint' => $fingerprint])
        ->assertForbidden()->assertJsonPath('message', 'You don\'t have access to manage CIC settings.');

    expect(CicPublicKey::query()->sole()->status)->toBe('pending');

    $spaced = strtolower(trim(chunk_split($fingerprint, 4, ' ')));

    $this->actingAs($this->checker)->postJson("/api/cic/public-keys/{$id}/verify", ['fingerprint' => $spaced])
        ->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.verified_by.id', $this->checker->id);

    $audit = AuditLog::query()->where('auditable_type', CicPublicKey::class)->where('action', 'verified')->sole();
    expect($audit->user_id)->toBe($this->checker->id)->and($audit->description)->toContain($fingerprint);
});

it('retires the active key when a new one is verified, in one step', function () {
    $first = cicUpload($this, CicTestKeys::valid())->json('data.id');
    $this->actingAs($this->checker)->postJson("/api/cic/public-keys/{$first}/verify", ['fingerprint' => CicTestKeys::fingerprint()])->assertOk();

    $second = cicUpload($this, CicTestKeys::valid('other'))->json('data.id');

    expect(CicPublicKey::activeKey()?->id)->toBe($first);

    $this->actingAs($this->checker)->postJson("/api/cic/public-keys/{$second}/verify", ['fingerprint' => CicTestKeys::fingerprint('other')])->assertOk();

    $old = CicPublicKey::query()->findOrFail($first);
    expect(CicPublicKey::activeKey()?->id)->toBe($second)
        ->and($old->status)->toBe('retired')
        ->and($old->retire_reason)->toBe('Replaced by '.CicTestKeys::fingerprint('other'))
        ->and($old->retired_by)->toBe($this->checker->id)
        ->and(AuditLog::query()->where('auditable_type', CicPublicKey::class)->where('auditable_id', $first)->where('action', 'retired')->exists())->toBeTrue();
});

it('refuses to verify a key that is not pending', function () {
    $id = cicUpload($this, CicTestKeys::valid())->json('data.id');
    $this->actingAs($this->uploader)->postJson("/api/cic/public-keys/{$id}/retire", ['reason' => 'Wrong file'])->assertOk();

    $this->actingAs($this->checker)->postJson("/api/cic/public-keys/{$id}/verify", ['fingerprint' => CicTestKeys::fingerprint()])
        ->assertUnprocessable()
        ->assertJsonPath('errors.fingerprint.0', 'This key is already retired; only a key waiting for verification can be verified.');
});

it('refuses to verify a pending key that has expired since it was uploaded', function () {
    $key = CicPublicKey::factory()->create(['uploaded_by' => $this->uploader->id, 'expires_at' => now()->subDay()]);

    $this->actingAs($this->checker)->postJson("/api/cic/public-keys/{$key->id}/verify", ['fingerprint' => $key->fingerprint])
        ->assertUnprocessable()
        ->assertJsonPath('errors.fingerprint.0', 'This key has expired since it was uploaded, so it can\'t be made active. Upload CIC\'s current key.');
});

it('retires a key with a reason, and never twice', function () {
    $id = cicUpload($this, CicTestKeys::valid())->json('data.id');

    $this->actingAs($this->uploader)->postJson("/api/cic/public-keys/{$id}/retire", [])->assertUnprocessable()
        ->assertJsonPath('errors.reason.0', 'Give a reason for retiring the key.');
    $this->actingAs($this->uploader)->postJson("/api/cic/public-keys/{$id}/retire", ['reason' => str_repeat('x', 501)])->assertUnprocessable();

    $this->actingAs($this->uploader)->postJson("/api/cic/public-keys/{$id}/retire", ['reason' => 'CIC sent a new key'])
        ->assertOk()
        ->assertJsonPath('data.status', 'retired')
        ->assertJsonPath('data.retire_reason', 'CIC sent a new key')
        ->assertJsonPath('data.retired_by.id', $this->uploader->id);

    $this->actingAs($this->uploader)->postJson("/api/cic/public-keys/{$id}/retire", ['reason' => 'Again'])->assertUnprocessable()
        ->assertJsonPath('errors.reason.0', 'This key is already retired.');

    $audit = AuditLog::query()->where('auditable_type', CicPublicKey::class)->where('action', 'retired')->sole();
    expect($audit->new_values['retire_reason'])->toBe('CIC sent a new key')->and($audit->description)->toContain(CicTestKeys::fingerprint());
});

it('lists the history newest first without the armored text', function () {
    $older = CicPublicKey::factory()->retired()->create();
    $newer = cicUpload($this, CicTestKeys::valid())->json('data.id');

    $this->actingAs($this->checker)->getJson('/api/cic/public-keys')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $newer)
        ->assertJsonPath('data.0.can_verify', true)
        ->assertJsonPath('data.1.id', $older->id)
        ->assertJsonMissingPath('data.0.armored')
        ->assertJsonMissingPath('data.1.armored');

    $this->actingAs(CicTestKeys::staff([CicAccess::VIEW]))->getJson('/api/cic/public-keys')->assertForbidden();
    $this->actingAs(CicTestKeys::staff([CicAccess::SETTINGS], viewAll: false))->getJson('/api/cic/public-keys')
        ->assertForbidden()->assertJsonPath('message', CicAccess::VIEW_ALL_REFUSAL);
});

it('answers 404 for every key route while CIC is off, before looking the key up', function () {
    config(['cic.enabled' => false]);

    $this->actingAs($this->checker)->getJson('/api/cic/public-keys')->assertNotFound();
    $this->actingAs($this->checker)->postJson('/api/cic/public-keys/999999/verify', ['fingerprint' => 'x'])->assertNotFound();
    $this->actingAs($this->checker)->postJson('/api/cic/public-keys', ['armored' => 'x'])->assertNotFound();
});
