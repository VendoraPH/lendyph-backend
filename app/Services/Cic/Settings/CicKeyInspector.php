<?php

namespace App\Services\Cic\Settings;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;

/**
 * Reads an uploaded CIC public key with GnuPG (prompt §13.1), never trusting
 * its file name or user id:
 *
 *     gpg --batch --no-autostart --show-keys --with-colons --fixed-list-mode --with-fingerprint
 *
 * The armor goes in on stdin, in a temporary GNUPGHOME made 0700 and deleted
 * in a `finally`, so no keyring keeps it. `--show-keys` imports nothing.
 *
 * It refuses, in words (CicKeyRefusedException): more than 64 KB; anything
 * that isn't one ASCII-armored public key block; any secret or private key;
 * more than one primary key; a revoked, expired or not-yet-valid key; and a key
 * with no key or subkey that can encrypt now. A missing gpg refuses too, never
 * falls back.
 */
final class CicKeyInspector
{
    /** The largest key text accepted. CIC's public key is a few KB. */
    public const MAX_BYTES = 65536;

    private const BEGIN_PUBLIC = '-----BEGIN PGP PUBLIC KEY BLOCK-----';

    private const TIMEOUT_SECONDS = 20;

    /** Colon-listing validity letters that make a key or subkey unusable. */
    private const UNUSABLE = ['r', 'e', 'i', 'd', 'n'];

    /** OpenPGP public key algorithm ids (RFC 4880, RFC 6637, RFC 9580) in words. */
    private const ALGORITHMS = [
        1 => 'RSA', 2 => 'RSA', 3 => 'RSA', 16 => 'ElGamal', 17 => 'DSA', 18 => 'ECDH', 19 => 'ECDSA',
        22 => 'EdDSA', 25 => 'X25519', 26 => 'X448', 27 => 'Ed25519', 28 => 'Ed448',
    ];

    public const GPG_MISSING = 'The server can\'t check keys because GnuPG isn\'t installed on it. Ask the administrator to install it, then upload the key again.';

    public const TOO_LARGE = 'The key is larger than 64 KB. CIC\'s public key is much smaller: upload only the key CIC sent.';

    public const PRIVATE_KEY = 'This is a private key. Upload only CIC\'s public key; a private key must never leave its owner.';

    public const NOT_A_KEY = 'This isn\'t an OpenPGP public key in text form. Upload the .asc file CIC sent, which begins with "-----BEGIN PGP PUBLIC KEY BLOCK-----".';

    public const SEVERAL_KEYS = 'This file holds more than one key. Upload CIC\'s key on its own.';

    public const REVOKED = 'This key has been revoked by its owner, so it can\'t be used. Ask CIC for its current key.';

    public const EXPIRED = 'This key has expired, so it can\'t be used. Ask CIC for its current key.';

    public const NOT_YET_VALID = 'This key isn\'t valid yet: its creation date is in the future. Check the key with CIC.';

    public const INVALID = 'GnuPG reads this key as invalid, so it can\'t be used. Ask CIC for its current key.';

    public const CANNOT_ENCRYPT = 'This key can\'t encrypt: it has no key or subkey for encryption that is valid now. Ask CIC for its encryption key.';

    public function binary(): string
    {
        return (string) config('cic.gpg_binary', '/usr/bin/gpg');
    }

    public function available(): bool
    {
        $binary = $this->binary();

        return $binary !== '' && is_file($binary) && is_executable($binary);
    }

    /** @throws CicKeyRefusedException */
    public function inspect(string $armored): InspectedCicKey
    {
        if (strlen($armored) > self::MAX_BYTES) {
            throw new CicKeyRefusedException(self::TOO_LARGE);
        }

        if (preg_match('/-----BEGIN PGP (PRIVATE|SECRET) KEY BLOCK-----/i', $armored) === 1) {
            throw new CicKeyRefusedException(self::PRIVATE_KEY);
        }

        if (! mb_check_encoding($armored, 'UTF-8') || str_contains($armored, "\0")) {
            throw new CicKeyRefusedException(self::NOT_A_KEY);
        }

        $blocks = substr_count($armored, self::BEGIN_PUBLIC);

        if ($blocks === 0) {
            throw new CicKeyRefusedException(self::NOT_A_KEY);
        }

        if ($blocks > 1) {
            throw new CicKeyRefusedException(self::SEVERAL_KEYS);
        }

        if (! $this->available()) {
            throw new CicKeyRefusedException(self::GPG_MISSING);
        }

        return $this->read($this->listing($armored));
    }

    /** gpg's colon listing of the armor, from a throwaway home. */
    private function listing(string $armored): string
    {
        $home = sys_get_temp_dir().'/cic-gpg-'.bin2hex(random_bytes(16));

        try {
            if (! @mkdir($home, 0700) || ! @chmod($home, 0700)) {
                throw new CicKeyRefusedException('The server couldn\'t make a private folder to check the key. Try again, or ask the administrator.');
            }

            $process = new Process(
                [$this->binary(), '--batch', '--no-tty', '--no-autostart', '--show-keys', '--with-colons', '--fixed-list-mode', '--with-fingerprint'],
                null,
                ['GNUPGHOME' => $home, 'LC_ALL' => 'C'],
                $armored,
                self::TIMEOUT_SECONDS,
            );
            $process->run();

            if ($process->getExitCode() !== 0) {
                throw new CicKeyRefusedException(self::NOT_A_KEY);
            }

            return $process->getOutput();
        } catch (ProcessException) {
            throw new CicKeyRefusedException('GnuPG couldn\'t read the key in time. Try again, or ask the administrator.');
        } finally {
            File::deleteDirectory($home);
        }
    }

    private function read(string $listing): InspectedCicKey
    {
        $records = array_values(array_filter(array_map(
            fn (string $line) => explode(':', $line),
            preg_split('/\r?\n/', $listing) ?: [],
        ), fn (array $fields) => $fields[0] !== ''));

        $types = array_column($records, 0);

        if (in_array('sec', $types, true) || in_array('ssb', $types, true)) {
            throw new CicKeyRefusedException(self::PRIVATE_KEY);
        }

        $primaries = array_keys($types, 'pub', true);

        if ($primaries === []) {
            throw new CicKeyRefusedException(self::NOT_A_KEY);
        }

        if (count($primaries) > 1) {
            throw new CicKeyRefusedException(self::SEVERAL_KEYS);
        }

        $pub = $records[$primaries[0]];
        $fingerprint = $this->fingerprintAfter($records, $primaries[0]);
        $created = $this->time($pub[5] ?? '');
        $expires = $this->time($pub[6] ?? '');
        $validity = $pub[1] ?? '';
        $now = Carbon::now();

        if ($fingerprint === null) {
            throw new CicKeyRefusedException(self::NOT_A_KEY);
        }

        if ($validity === 'r') {
            throw new CicKeyRefusedException(self::REVOKED);
        }

        if ($validity === 'e' || ($expires !== null && $expires->lessThanOrEqualTo($now))) {
            throw new CicKeyRefusedException(self::EXPIRED);
        }

        if ($created !== null && $created->greaterThan($now)) {
            throw new CicKeyRefusedException(self::NOT_YET_VALID);
        }

        if (in_array($validity, self::UNUSABLE, true)) {
            throw new CicKeyRefusedException(self::INVALID);
        }

        if (! $this->canEncrypt($records, $now)) {
            throw new CicKeyRefusedException(self::CANNOT_ENCRYPT);
        }

        return new InspectedCicKey(
            fingerprint: $fingerprint,
            keyId: strtoupper($pub[4] ?? ''),
            algorithm: $this->algorithm($pub),
            userIds: $this->userIds($records),
            createdAt: $created,
            expiresAt: $expires,
        );
    }

    /** @param  list<list<string>>  $records */
    private function fingerprintAfter(array $records, int $index): ?string
    {
        $next = $records[$index + 1] ?? null;

        if ($next === null || $next[0] !== 'fpr') {
            return null;
        }

        $fingerprint = strtoupper($next[9] ?? '');

        return preg_match('/^([0-9A-F]{40}|[0-9A-F]{64})$/', $fingerprint) === 1 ? $fingerprint : null;
    }

    /**
     * Whether the primary key or a subkey can encrypt now: its own usage holds
     * "e", and it is neither revoked, expired, invalid nor dated in the future.
     *
     * @param  list<list<string>>  $records
     */
    private function canEncrypt(array $records, Carbon $now): bool
    {
        foreach ($records as $fields) {
            if (! in_array($fields[0], ['pub', 'sub'], true)) {
                continue;
            }

            $created = $this->time($fields[5] ?? '');
            $expires = $this->time($fields[6] ?? '');

            if (str_contains($fields[11] ?? '', 'e')
                && ! in_array($fields[1] ?? '', self::UNUSABLE, true)
                && ($expires === null || $expires->greaterThan($now))
                && ($created === null || $created->lessThanOrEqualTo($now))) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $pub */
    private function algorithm(array $pub): string
    {
        $name = self::ALGORITHMS[(int) ($pub[3] ?? 0)] ?? 'Algorithm '.($pub[3] ?? '?');
        $curve = trim($pub[16] ?? '');
        $bits = (int) ($pub[2] ?? 0);

        $words = match (true) {
            $curve !== '' => "{$name} ({$curve})",
            $bits > 0 => "{$name} {$bits}",
            default => $name,
        };

        return mb_substr($words, 0, 40);
    }

    /**
     * The user ids that aren't revoked or expired, unescaped ("\x3a" is gpg's
     * colon).
     *
     * @param  list<list<string>>  $records
     * @return list<string>
     */
    private function userIds(array $records): array
    {
        $ids = [];

        foreach ($records as $fields) {
            if ($fields[0] !== 'uid' || in_array($fields[1] ?? '', ['r', 'e'], true)) {
                continue;
            }

            $id = (string) preg_replace_callback('/\\\\x([0-9a-fA-F]{2})/', fn (array $m) => chr((int) hexdec($m[1])), $fields[9] ?? '');
            $id = trim((string) preg_replace('/\p{Cc}/u', '', mb_scrub($id, 'UTF-8')));

            if ($id !== '') {
                $ids[] = mb_substr($id, 0, 255);
            }
        }

        return array_values(array_unique($ids));
    }

    /** A colon listing's time (seconds since 1970 with --fixed-list-mode), or null. */
    private function time(string $value): ?Carbon
    {
        return ctype_digit($value) && $value !== '0' ? Carbon::createFromTimestamp((int) $value, config('app.timezone')) : null;
    }
}
