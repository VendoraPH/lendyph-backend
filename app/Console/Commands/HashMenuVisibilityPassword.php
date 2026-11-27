<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * Menu Visibility's administrator override (finish run round 6): turns the
 * password, typed twice with hidden input, into the bcrypt hash a deployment
 * sets as MENU_VISIBILITY_OVERRIDE_PASSWORD_HASH (single-quoted in a .env
 * file, since it holds `$` signs). It prints only the hash and writes it
 * nowhere: whoever sets the deployment's secret copies it from here.
 */
#[Signature('menu-visibility:hash-password')]
#[Description('Print the bcrypt hash of the Menu Visibility administrator override password, for MENU_VISIBILITY_OVERRIDE_PASSWORD_HASH')]
class HashMenuVisibilityPassword extends Command
{
    /** The bcrypt cost, never lower, whatever BCRYPT_ROUNDS the machine running this sets. */
    public const ROUNDS = 12;

    public const MIN_CHARACTERS = 12;

    /** bcrypt reads no further: a longer password would match on its first 72 bytes alone. */
    public const MAX_BYTES = 72;

    public function handle(): int
    {
        $password = (string) $this->secret('Administrator override password');
        $again = (string) $this->secret('Type it again');

        if (! hash_equals($password, $again)) {
            $this->error("The two passwords don't match.");

            return self::FAILURE;
        }

        if (mb_strlen($password) < self::MIN_CHARACTERS) {
            $this->error('Use at least '.self::MIN_CHARACTERS.' characters.');

            return self::FAILURE;
        }

        if (strlen($password) > self::MAX_BYTES) {
            $this->error('Use at most '.self::MAX_BYTES.' bytes: bcrypt ignores the rest.');

            return self::FAILURE;
        }

        $this->line(self::hash($password));

        return self::SUCCESS;
    }

    public static function hash(#[SensitiveParameter] string $password): string
    {
        return Hash::driver('bcrypt')->make($password, [
            'rounds' => max(self::ROUNDS, (int) config('hashing.bcrypt.rounds', self::ROUNDS)),
        ]);
    }
}
