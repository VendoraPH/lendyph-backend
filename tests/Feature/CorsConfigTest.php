<?php

use Tests\TestCase;

uses(TestCase::class);

/**
 * Re-evaluates config/cors.php with a given CORS_ALLOWED_ORIGINS.
 *
 * The derivation is what these tests are about, so they must run the real
 * expression rather than override the resolved value with config() — doing that
 * bypasses the explode entirely and the test passes against the very bug it is
 * meant to catch.
 *
 * @return array<int, string>
 */
function corsOriginsFor(?string $env): array
{
    $previous = $_ENV['CORS_ALLOWED_ORIGINS'] ?? null;

    if ($env === null) {
        unset($_ENV['CORS_ALLOWED_ORIGINS'], $_SERVER['CORS_ALLOWED_ORIGINS']);
        putenv('CORS_ALLOWED_ORIGINS');
    } else {
        $_ENV['CORS_ALLOWED_ORIGINS'] = $env;
        $_SERVER['CORS_ALLOWED_ORIGINS'] = $env;
        putenv("CORS_ALLOWED_ORIGINS={$env}");
    }

    try {
        return (require __DIR__.'/../../config/cors.php')['allowed_origins'];
    } finally {
        if ($previous === null) {
            unset($_ENV['CORS_ALLOWED_ORIGINS'], $_SERVER['CORS_ALLOWED_ORIGINS']);
            putenv('CORS_ALLOWED_ORIGINS');
        } else {
            $_ENV['CORS_ALLOWED_ORIGINS'] = $previous;
            $_SERVER['CORS_ALLOWED_ORIGINS'] = $previous;
            putenv("CORS_ALLOWED_ORIGINS={$previous}");
        }
    }
}

/**
 * php-cors treats a single-element origin list as "one static origin"
 * (CorsService::isSingleOriginAllowed), so a bare explode() on an unset var
 * yields [''] — count 1 — and stamps a literal empty
 * `Access-Control-Allow-Origin:` header on every response. An empty array
 * instead falls to the dynamic branch and emits no header at all.
 */
it('resolves an unset CORS_ALLOWED_ORIGINS to an empty array, not ['."''".']', function () {
    expect(corsOriginsFor(null))->toBe([]);
});

it('resolves a blank CORS_ALLOWED_ORIGINS to an empty array', function () {
    expect(corsOriginsFor(''))->toBe([]);
});

it('drops empty entries from a trailing or doubled comma', function () {
    expect(corsOriginsFor('https://app.lendyph.com,,'))->toBe(['https://app.lendyph.com']);
});

it('trims surrounding whitespace', function () {
    expect(corsOriginsFor(' https://app.lendyph.com , https://staging-app.lendyph.com '))
        ->toBe(['https://app.lendyph.com', 'https://staging-app.lendyph.com']);
});

it('is not wildcarded and never sends credentials', function () {
    // The whole point of publishing this config. A regression to the framework
    // default would re-open every API to any browser origin.
    expect(config('cors.allowed_origins'))->not->toContain('*')
        ->and(config('cors.supports_credentials'))->toBeFalse();
});
