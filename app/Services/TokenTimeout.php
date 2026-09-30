<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * The API token lifetime, in minutes, from `auth.token_timeout`
 * (`AUTH_TOKEN_TIMEOUT`, default 30).
 *
 * It is how long a normal sign-in's token lives, how long a refreshed token
 * lives, and the idle window CheckTokenExpiry applies to a remembered one. It
 * must be a whole number above zero. Zero was once documented as "disable",
 * but addMinutes(0) mints a token that has already expired, so every request
 * after login answered 401. A token that never expires is not supported
 * either, so there is no value that turns the lifetime off.
 *
 * AppServiceProvider calls minutes() while booting, so a bad value stops the
 * application — every request and every artisan command, including a deploy's
 * config:cache — with the message below, instead of failing one login at a
 * time.
 */
final class TokenTimeout
{
    /**
     * @throws InvalidArgumentException when the configured value is not above zero
     */
    public static function minutes(): int
    {
        $minutes = (int) config('auth.token_timeout');

        if ($minutes <= 0) {
            throw new InvalidArgumentException(
                'AUTH_TOKEN_TIMEOUT must be a whole number of minutes greater than 0, got '
                .var_export(config('auth.token_timeout'), true).'. '
                .'Tokens always expire; unset it to use the default of 30.'
            );
        }

        return $minutes;
    }
}
