<?php

use App\Console\Commands\HashMenuVisibilityPassword;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

uses(TestCase::class);

/*
 * `php artisan menu-visibility:hash-password` (finish run round 6): asks for
 * the administrator override password twice, hidden, and prints only its
 * bcrypt hash for whoever sets MENU_VISIBILITY_OVERRIDE_PASSWORD_HASH. It
 * writes nothing anywhere itself.
 */

it('prints only the bcrypt hash of the password typed twice', function () {
    $this->artisan('menu-visibility:hash-password')
        ->expectsQuestion('Administrator override password', 'Throwaway override 2026!')
        ->expectsQuestion('Type it again', 'Throwaway override 2026!')
        ->expectsOutputToContain('$2y$12$')
        ->doesntExpectOutputToContain('Throwaway override 2026!')
        ->assertSuccessful();
});

it('hashes with bcrypt at cost 12 at least, whatever the environment sets', function () {
    config(['hashing.bcrypt.rounds' => 4]);

    $hash = HashMenuVisibilityPassword::hash('Throwaway override 2026!');

    expect($hash)->toStartWith('$2y$12$')
        ->and(strlen($hash))->toBe(60)
        ->and(Hash::driver('bcrypt')->check('Throwaway override 2026!', $hash))->toBeTrue()
        ->and(Hash::driver('bcrypt')->check('Throwaway override 2026?', $hash))->toBeFalse();
});

it('refuses two passwords that differ', function () {
    $this->artisan('menu-visibility:hash-password')
        ->expectsQuestion('Administrator override password', 'Throwaway override 2026!')
        ->expectsQuestion('Type it again', 'Throwaway override 2027!')
        ->expectsOutputToContain("The two passwords don't match.")
        ->doesntExpectOutputToContain('$2y$')
        ->assertFailed();
});

it('refuses a password bcrypt can\'t hold whole, or too short to protect anything', function (string $password, string $refusal) {
    $this->artisan('menu-visibility:hash-password')
        ->expectsQuestion('Administrator override password', $password)
        ->expectsQuestion('Type it again', $password)
        ->expectsOutputToContain($refusal)
        ->doesntExpectOutputToContain('$2y$')
        ->assertFailed();
})->with([
    'blank' => ['', 'Use at least 12 characters.'],
    'short' => ['Elevenchar1', 'Use at least 12 characters.'],
    'over 72 bytes' => [str_repeat('long password ', 6), 'Use at most 72 bytes: bcrypt ignores the rest.'],
]);
