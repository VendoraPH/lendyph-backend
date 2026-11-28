<?php

namespace Tests\Feature\Cic\Submissions;

use App\Models\CicCapture;
use App\Models\CicCaptureRecord;
use App\Models\CicPublicKey;
use App\Models\CicSetting;
use App\Models\User;
use App\Services\Cic\CicAccess;
use App\Services\Cic\Contracts\ValidatesCicCapture;
use FilesystemIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Process\Process;

/**
 * CIC files in tests: staff with chosen permissions, a fake validator returning
 * chosen issues (the records slice provides the real one), captures holding
 * chosen records, and a throwaway GnuPG key pair made in the test's own
 * temporary home (never committed) to encrypt to and decrypt with.
 */
trait CicSubmissionsTestHelpers
{
    public const PROVIDER = 'TEST1234';

    /** @var list<string> GnuPG homes this test made */
    private array $gpgHomes = [];

    public function setUpCic(): void
    {
        config(['cic.enabled' => true]);
        CicSetting::factory()->create(['provider_code' => self::PROVIDER]);
        $this->fakeIssues([]);
    }

    /** @param  list<array<string, mixed>>  $issues */
    public function fakeIssues(array $issues): void
    {
        app()->instance(ValidatesCicCapture::class, new class($issues) implements ValidatesCicCapture
        {
            /** @param  list<array<string, mixed>>  $issues */
            public function __construct(private readonly array $issues) {}

            public function issues(CicCapture $capture): array
            {
                return $this->issues;
            }
        });
    }

    /** @param  list<string>  $permissions */
    public function cicUser(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo([...$permissions, 'branches:view_all']);

        return $user;
    }

    public function cicAll(): User
    {
        return $this->cicUser(CicAccess::PERMISSIONS);
    }

    /**
     * A complete capture holding the records: [type, key, values].
     *
     * @param  list<array{0: string, 1: string, 2?: array<string, string>}>  $records
     */
    public function captureWith(array $records, string $kind = CicCapture::KIND_MONTH_END, string $date = '2026-10-31', bool $complete = true, ?CicCapture $corrects = null): CicCapture
    {
        /** @var CicCapture $capture */
        $capture = CicCapture::factory()->create(['kind' => $kind, 'reference_date' => $date]);

        if ($corrects !== null) {
            $capture->forceFill(['corrects_capture_id' => $corrects->id])->save();
        }

        foreach ($records as $record) {
            [$type, $key] = $record;
            $values = $record[2] ?? [$type.'1' => $type, $type.'4' => date('dmY', strtotime($date)), $type.'5' => $key];

            CicCaptureRecord::factory()->for($capture, 'capture')->create([
                'record_type' => $type,
                'record_key' => $key,
                'values' => $values,
                'checksum' => CicCaptureRecord::checksumOf($values),
            ]);
        }

        if ($complete) {
            $capture->forceFill([
                'status' => CicCapture::STATUS_COMPLETE,
                'subject_count' => count(array_filter($records, fn ($r) => $r[0] === 'ID')),
                'contract_count' => count(array_filter($records, fn ($r) => in_array($r[0], ['CI', 'CN'], true))),
                'event_count' => count(array_filter($records, fn ($r) => $r[0] === 'NE')),
                'checksum' => $capture->computeChecksum(),
                'finished_at' => now(),
            ])->save();
        }

        return $capture->refresh();
    }

    public function simpleCapture(string $kind = CicCapture::KIND_MONTH_END, string $date = '2026-10-31', ?CicCapture $corrects = null): CicCapture
    {
        return $this->captureWith([['ID', 'BRW-00000001'], ['CI', 'LN-000001']], $kind, $date, corrects: $corrects);
    }

    /**
     * A throwaway key pair (Ed25519 with a Curve25519 encryption subkey, no
     * passphrase) in a temporary home of its own; activateTestKey() makes it
     * the active, verified CIC key.
     *
     * @return array{home: string, fingerprint: string, armored: string}
     */
    public function makeTestKey(): array
    {
        $home = sys_get_temp_dir().'/cic-test-'.bin2hex(random_bytes(6));
        mkdir($home, 0700);
        $this->gpgHomes[] = $home;

        file_put_contents($home.'/params', implode("\n", [
            '%no-protection',
            'Key-Type: EDDSA',
            'Key-Curve: ed25519',
            'Subkey-Type: ECDH',
            'Subkey-Curve: cv25519',
            'Name-Real: CIC Test Recipient',
            'Name-Email: cic-test@example.test',
            'Expire-Date: 1y',
            '%commit',
            '',
        ]));

        $this->gpg($home, ['--batch', '--gen-key', $home.'/params']);
        $listing = $this->gpg($home, ['--batch', '--with-colons', '--fingerprint', '--list-keys']);
        preg_match('/^fpr:(?:[^:]*:){8}([0-9A-F]{40,64}):/m', $listing, $match);
        $fingerprint = $match[1] ?? throw new RuntimeException('The test key has no fingerprint.');

        return [
            'home' => $home,
            'fingerprint' => $fingerprint,
            'armored' => $this->gpg($home, ['--batch', '--armor', '--export', $fingerprint]),
        ];
    }

    /** @param  array{fingerprint: string, armored: string}  $key  */
    public function activateTestKey(array $key, array $overrides = []): CicPublicKey
    {
        return CicPublicKey::factory()->active()->create([
            'fingerprint' => $key['fingerprint'],
            'key_id' => substr($key['fingerprint'], -16),
            'algorithm' => 'EdDSA ed25519',
            'user_ids' => ['CIC Test Recipient <cic-test@example.test>'],
            'armored' => $key['armored'],
            ...$overrides,
        ]);
    }

    /** Decrypt with the test's secret key. */
    public function decryptWith(string $home, string $encrypted): string
    {
        $in = $home.'/in.gpg';
        $out = $home.'/out.zip';
        file_put_contents($in, $encrypted);
        @unlink($out);
        $this->gpg($home, ['--batch', '--yes', '--output', $out, '--decrypt', $in]);

        return (string) file_get_contents($out);
    }

    /** @param  list<string>  $arguments */
    public function gpg(string $home, array $arguments): string
    {
        $process = new Process([(string) config('cic.gpg_binary'), ...$arguments], $home, ['GNUPGHOME' => $home, 'LC_ALL' => 'C']);
        $process->setTimeout(60);
        $process->mustRun();

        return $process->getOutput();
    }

    /** Folders the encryptor left in its temporary root (there should be none). */
    public function leftoverEncryptionFolders(): array
    {
        $root = storage_path('app/cic-tmp');

        if (! is_dir($root)) {
            return [];
        }

        return array_values(array_map(fn (SplFileInfo $entry) => $entry->getFilename(), iterator_to_array(new FilesystemIterator($root), false)));
    }

    public function tearDownTestKeys(): void
    {
        foreach ($this->gpgHomes as $home) {
            $gpgconf = dirname((string) config('cic.gpg_binary')).'/gpgconf';

            if (is_executable($gpgconf)) {
                (new Process([$gpgconf, '--kill', 'all'], null, ['GNUPGHOME' => $home]))->run();
            }

            $this->removeTree($home);
        }

        $this->gpgHomes = [];
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (new FilesystemIterator($path) as $entry) {
            $this->removeTree($entry->getPathname());
        }

        @rmdir($path);
    }
}
