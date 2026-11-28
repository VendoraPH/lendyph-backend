<?php

namespace App\Services\Cic\Settings;

use Illuminate\Support\Carbon;

/**
 * What CicKeyInspector read from an uploaded public key: one primary key that
 * is valid now and can encrypt.
 */
final class InspectedCicKey
{
    /**
     * @param  string  $fingerprint  upper-case hex, no spaces
     * @param  string  $keyId  the primary key's long id, upper-case hex
     * @param  string  $algorithm  the primary key's algorithm in words ("RSA 4096", "EdDSA (ed25519)")
     * @param  list<string>  $userIds  the key's user ids that aren't revoked
     */
    public function __construct(
        public readonly string $fingerprint,
        public readonly string $keyId,
        public readonly string $algorithm,
        public readonly array $userIds,
        public readonly ?Carbon $createdAt,
        public readonly ?Carbon $expiresAt,
    ) {}
}
