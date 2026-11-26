<?php

namespace App\Services\MenuVisibility;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The administrator override's lockout (finish run round 6; security review
 * of #397): 5 tries in 15 minutes, on two keys at once, the user and the
 * caller's address.
 *
 * Each try is counted before its password is checked (take()), so tries sent
 * at once are counted at once: of any number sent together, at most 5 reach the
 * check. A right password gives its try back (giveBack()), so only wrong ones
 * add up. The window starts at a key's first try. While TRUSTED_PROXIES is
 * empty the address is the frontend proxy's, so the address key is in effect
 * the deployment's, which suits a shared secret. The keys hold ids and a hash
 * of the address, never the password.
 */
final class OverrideLockout
{
    public const MAX_TRIES = 5;

    public const WINDOW_MINUTES = 15;

    /**
     * Count this try on both keys. Null when it may go on to the password
     * check; otherwise the seconds until the caller may try again.
     */
    public function take(User $user, string $ip): ?int
    {
        $wait = null;

        foreach ($this->keys($user, $ip) as $key) {
            if (RateLimiter::hit($key, self::WINDOW_MINUTES * 60) > self::MAX_TRIES) {
                $wait = max($wait ?? 0, RateLimiter::availableIn($key));
            }
        }

        return $wait;
    }

    /** Give a right password's try back on both keys. */
    public function giveBack(User $user, string $ip): void
    {
        foreach ($this->keys($user, $ip) as $key) {
            if ((int) RateLimiter::attempts($key) > 0) {
                RateLimiter::decrement($key, self::WINDOW_MINUTES * 60);
            }
        }
    }

    /** "Too many tries. Try again in 15 minutes." (1 minute at the least). */
    public static function lockedMessage(int $retryAfterSeconds): string
    {
        $minutes = max(1, (int) ceil($retryAfterSeconds / 60));

        return 'Too many tries. Try again in '.$minutes.' '.($minutes === 1 ? 'minute' : 'minutes').'.';
    }

    /** @return list<string> */
    private function keys(User $user, string $ip): array
    {
        return [
            'menu-visibility-override:user:'.$user->getKey(),
            'menu-visibility-override:ip:'.hash('sha256', $ip),
        ];
    }
}
