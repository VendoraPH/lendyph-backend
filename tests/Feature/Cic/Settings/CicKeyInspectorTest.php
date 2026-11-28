<?php

use App\Services\Cic\Settings\CicKeyInspector;
use App\Services\Cic\Settings\CicKeyRefusedException;
use Tests\Feature\Cic\Settings\CicTestKeys;
use Tests\TestCase;

uses(TestCase::class);

/*
 * CicKeyInspector reads an uploaded key with GnuPG in a throwaway home and
 * refuses, in words, anything that isn't one public key that can encrypt now.
 * The keys are made by GnuPG inside the test.
 */

function cicInspectorRefusal(string $armored): string
{
    try {
        app(CicKeyInspector::class)->inspect($armored);
    } catch (CicKeyRefusedException $e) {
        return $e->getMessage();
    }

    return 'accepted';
}

/** @return list<string> the inspector's temporary homes left behind */
function cicInspectorHomes(): array
{
    return glob(sys_get_temp_dir().'/cic-gpg-*') ?: [];
}

it('reads a valid key: fingerprint, key id, algorithm, user ids and dates', function () {
    $key = app(CicKeyInspector::class)->inspect(CicTestKeys::valid());

    expect($key->fingerprint)->toBe(CicTestKeys::fingerprint())
        ->and($key->fingerprint)->toMatch('/^[0-9A-F]{40}$/')
        ->and($key->keyId)->toBe(substr(CicTestKeys::fingerprint(), -16))
        ->and($key->algorithm)->toBe('EdDSA (ed25519)')
        ->and($key->userIds)->toBe(['CIC Test cic <cic@example.invalid>'])
        ->and($key->createdAt?->isToday())->toBeTrue()
        ->and($key->expiresAt?->isFuture())->toBeTrue();
});

it('deletes its temporary home after reading, and after refusing', function () {
    $before = cicInspectorHomes();

    app(CicKeyInspector::class)->inspect(CicTestKeys::valid());
    cicInspectorRefusal(CicTestKeys::signOnly());

    expect(cicInspectorHomes())->toBe($before);
});

it('refuses each unusable key in words', function (Closure $armor, string $message) {
    expect(cicInspectorRefusal($armor()))->toBe($message);
})->with([
    'a secret key' => [fn () => CicTestKeys::secret(), CicKeyInspector::PRIVATE_KEY],
    'two keys' => [fn () => CicTestKeys::twoKeys(), CicKeyInspector::SEVERAL_KEYS],
    'two key blocks' => [fn () => CicTestKeys::valid().CicTestKeys::valid('other'), CicKeyInspector::SEVERAL_KEYS],
    'a revoked key' => [fn () => CicTestKeys::revoked(), CicKeyInspector::REVOKED],
    'an expired key' => [fn () => CicTestKeys::expired(), CicKeyInspector::EXPIRED],
    'a key dated in the future' => [fn () => CicTestKeys::notYetValid(), CicKeyInspector::NOT_YET_VALID],
    'a key that can only sign' => [fn () => CicTestKeys::signOnly(), CicKeyInspector::CANNOT_ENCRYPT],
    'plain text' => [fn () => 'hello, this is CIC', CicKeyInspector::NOT_A_KEY],
    'a broken key block' => [fn () => "-----BEGIN PGP PUBLIC KEY BLOCK-----\n\nmQINBGBroken==\n-----END PGP PUBLIC KEY BLOCK-----\n", CicKeyInspector::NOT_A_KEY],
    'binary bytes' => [fn () => "\x99\x01\x0d\x04\0\xff", CicKeyInspector::NOT_A_KEY],
    'more than 64 KB' => [fn () => CicTestKeys::valid().str_repeat('A', CicKeyInspector::MAX_BYTES), CicKeyInspector::TOO_LARGE],
]);

it('refuses a private key block before running GnuPG', function () {
    $secret = CicTestKeys::secret();
    config(['cic.gpg_binary' => '/nonexistent/gpg']);

    expect(cicInspectorRefusal($secret))->toBe(CicKeyInspector::PRIVATE_KEY);
});

it('refuses in words when GnuPG is missing, never falling back', function () {
    $armor = CicTestKeys::valid();
    config(['cic.gpg_binary' => '/nonexistent/gpg']);

    expect(app(CicKeyInspector::class)->available())->toBeFalse()
        ->and(cicInspectorRefusal($armor))->toBe(CicKeyInspector::GPG_MISSING);
});
