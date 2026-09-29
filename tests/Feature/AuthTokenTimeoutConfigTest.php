<?php

use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Re-evaluates config/auth.php with a given AUTH_TOKEN_TIMEOUT.
 *
 * Runs the real expression rather than setting the resolved value with
 * config(), which would skip the env() read these tests are about. Same
 * approach as CorsConfigTest.
 */
function tokenTimeoutFor(?string $env): mixed
{
    $previous = $_ENV['AUTH_TOKEN_TIMEOUT'] ?? null;

    if ($env === null) {
        unset($_ENV['AUTH_TOKEN_TIMEOUT'], $_SERVER['AUTH_TOKEN_TIMEOUT']);
        putenv('AUTH_TOKEN_TIMEOUT');
    } else {
        $_ENV['AUTH_TOKEN_TIMEOUT'] = $env;
        $_SERVER['AUTH_TOKEN_TIMEOUT'] = $env;
        putenv("AUTH_TOKEN_TIMEOUT={$env}");
    }

    try {
        return (require __DIR__.'/../../config/auth.php')['token_timeout'];
    } finally {
        if ($previous === null) {
            unset($_ENV['AUTH_TOKEN_TIMEOUT'], $_SERVER['AUTH_TOKEN_TIMEOUT']);
            putenv('AUTH_TOKEN_TIMEOUT');
        } else {
            $_ENV['AUTH_TOKEN_TIMEOUT'] = $previous;
            $_SERVER['AUTH_TOKEN_TIMEOUT'] = $previous;
            putenv("AUTH_TOKEN_TIMEOUT={$previous}");
        }
    }
}

it('defaults the token timeout to 30 minutes', function () {
    expect(tokenTimeoutFor(null))->toBe(30);
});

it('reads a set AUTH_TOKEN_TIMEOUT as an integer', function () {
    expect(tokenTimeoutFor('45'))->toBe(45);
});

it('lets a login succeed when the timeout comes from the environment', function () {
    // The value a box with AUTH_TOKEN_TIMEOUT=45 actually resolves to. Before
    // the cast it was the string '45', and Carbon::addMinutes() threw on it.
    config(['auth.token_timeout' => tokenTimeoutFor('45')]);
    $this->travelTo(Carbon::parse('2026-09-30 09:00:00'));

    $this->postJson('/api/auth/login', ['login' => 'super_admin', 'password' => 'password'])
        ->assertOk();

    expect(PersonalAccessToken::latest('id')->first()->expires_at->toDateTimeString())
        ->toBe('2026-09-30 09:45:00');
});
