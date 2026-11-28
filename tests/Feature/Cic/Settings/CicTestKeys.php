<?php

namespace Tests\Feature\Cic\Settings;

use App\Models\User;
use App\Services\BranchAccess;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * OpenPGP test keys made by GnuPG inside the test run, in a temporary home
 * that is deleted when the run ends. No key is ever committed. Each key is
 * made once per run and reused.
 */
final class CicTestKeys
{
    private static ?string $home = null;

    /** The GnuPG the keys are made with, read once, so a test that points CIC at a missing one still gets its keys. */
    private static ?string $binary = null;

    /** @var array<string, string> armor by name */
    private static array $armor = [];

    /** @var array<string, string> fingerprint by name */
    private static array $fingerprints = [];

    /** A staff user holding the permissions (and head-office access unless left out). */
    public static function staff(array $permissions, bool $viewAll = true): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($viewAll ? [...$permissions, BranchAccess::VIEW_ALL] : $permissions);

        return $user;
    }

    /** A valid key with an encryption subkey. */
    public static function valid(string $name = 'cic'): string
    {
        return self::make($name, fn (string $uid) => ['--quick-gen-key', $uid, 'future-default', 'default', '2y']);
    }

    public static function fingerprint(string $name = 'cic'): string
    {
        self::valid($name);

        return self::$fingerprints[$name];
    }

    /** An Ed25519 key that can only sign. */
    public static function signOnly(): string
    {
        return self::make('sign-only', fn (string $uid) => ['--quick-gen-key', $uid, 'ed25519', 'sign', 'never']);
    }

    /** A key made in 2020 that expired in 2021. */
    public static function expired(): string
    {
        return self::make('expired', fn (string $uid) => ['--faked-system-time', '20200101T000000', '--quick-gen-key', $uid, 'future-default', 'default', '1y']);
    }

    /** A key dated in 2099. */
    public static function notYetValid(): string
    {
        return self::make('future', fn (string $uid) => ['--faked-system-time', '20990101T000000', '--quick-gen-key', $uid, 'future-default', 'default', 'never']);
    }

    /** A key revoked by its own revocation certificate. */
    public static function revoked(): string
    {
        if (! isset(self::$armor['revoked'])) {
            self::make('revoked-source', fn (string $uid) => ['--quick-gen-key', $uid, 'future-default', 'default', 'never']);
            $fingerprint = self::$fingerprints['revoked-source'];
            $certificate = (string) file_get_contents(self::home()."/openpgp-revocs.d/{$fingerprint}.rev");
            self::gpg(['--import'], (string) preg_replace('/^:-----/m', '-----', $certificate));
            self::$armor['revoked'] = self::gpg(['--armor', '--export', $fingerprint]);
        }

        return self::$armor['revoked'];
    }

    /** The valid key's secret key, armored. */
    public static function secret(): string
    {
        self::valid();

        return self::gpg(['--pinentry-mode', 'loopback', '--passphrase', '', '--armor', '--export-secret-keys', self::$fingerprints['cic']]);
    }

    /** Two keys in one armored block. */
    public static function twoKeys(): string
    {
        self::valid('cic');
        self::valid('other');

        return self::gpg(['--armor', '--export', self::$fingerprints['cic'], self::$fingerprints['other']]);
    }

    /** @param  callable(string): list<string>  $arguments */
    private static function make(string $name, callable $arguments): string
    {
        if (! isset(self::$armor[$name])) {
            $uid = "CIC Test {$name} <{$name}@example.invalid>";
            self::gpg(['--pinentry-mode', 'loopback', '--passphrase', '', ...$arguments($uid)]);
            $listing = self::gpg(['--with-colons', '--fixed-list-mode', '--list-keys', "<{$name}@example.invalid>"]);
            preg_match('/^fpr:+([0-9A-F]+):/m', $listing, $match);
            self::$fingerprints[$name] = $match[1] ?? throw new RuntimeException("No fingerprint for test key {$name}.");
            self::$armor[$name] = self::gpg(['--armor', '--export', self::$fingerprints[$name]]);
        }

        return self::$armor[$name];
    }

    /** @param  list<string>  $arguments */
    private static function gpg(array $arguments, ?string $input = null): string
    {
        $process = new Process([self::$binary ??= (string) config('cic.gpg_binary'), '--batch', '--yes', ...$arguments], null, ['GNUPGHOME' => self::home()], $input, 120);
        $process->mustRun();

        return $process->getOutput();
    }

    private static function home(): string
    {
        if (self::$home === null) {
            $home = sys_get_temp_dir().'/cic-test-keys-'.bin2hex(random_bytes(8));
            mkdir($home, 0700);
            chmod($home, 0700);
            self::$home = $home;

            register_shutdown_function(static function () use ($home) {
                (new Process(['gpgconf', '--kill', 'all'], null, ['GNUPGHOME' => $home]))->run();
                File::deleteDirectory($home);
            });
        }

        return self::$home;
    }
}
