<?php

namespace App\Services\MenuVisibility;

use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * The administrator override's lockout (finish run round 6; security reviews
 * of #397): at most 5 tries reach the password check in any 15 minutes, on two
 * keys at once, the user and the caller's address.
 *
 * - Each try is counted before its password is checked (take()), one try at a
 *   time under one lock (LOCK), so tries sent together are counted in turn and
 *   never more than 5 get through. A try that can't get its turn within
 *   LOCK_WAIT_SECONDS is refused unchecked (it fails closed).
 * - The window slides: a key keeps the times of its counted tries, and a try
 *   goes on only while fewer than 5 fall in the last 15 minutes. There is no
 *   window edge to burst across, and a refused try isn't counted, so the
 *   lockout lifts 15 minutes after the oldest of the 5.
 * - A right password gives its try back (giveBack()), so only wrong ones add
 *   up.
 *
 * While TRUSTED_PROXIES is empty the address is the frontend proxy's, so the
 * address key is in effect the deployment's, which suits a shared secret. The
 * keys hold ids and a hash of the address, never the password.
 */
final class OverrideLockout
{
    public const MAX_TRIES = 5;

    public const WINDOW_MINUTES = 15;

    /** The one lock every try is counted under. */
    public const LOCK = 'menu-visibility:override-tries';

    /** How long a try waits for its turn before it is refused unchecked. */
    public const LOCK_WAIT_SECONDS = 3;

    /** What a try refused for want of a turn is told to wait. */
    private const BUSY_WAIT_SECONDS = 60;

    /** Stores that keep nothing between requests, so they can't count tries. */
    private const NOT_SHARED = ['array', 'null'];

    /** When this request's try was counted (milliseconds), for giveBack(). */
    private ?int $countedAt = null;

    /**
     * Whether the deployment's default cache store keeps the counts between
     * requests. An `array` or `null` store forgets them, which would let every
     * try through, so the override stays closed then (it fails closed).
     */
    public function canCount(): bool
    {
        $store = config('cache.default');
        $driver = is_string($store) ? config('cache.stores.'.$store.'.driver') : null;

        return is_string($driver) && ! in_array($driver, self::NOT_SHARED, true);
    }

    /**
     * Count this try on both keys. Null when it may go on to the password
     * check; otherwise the seconds until the caller may try again.
     */
    public function take(User $user, string $ip): ?int
    {
        $this->countedAt = null;

        try {
            return Cache::lock(self::LOCK, 10)->block(self::LOCK_WAIT_SECONDS, fn (): ?int => $this->count($user, $ip));
        } catch (LockTimeoutException) {
            return self::BUSY_WAIT_SECONDS;
        }
    }

    /** Give a right password's try back on both keys. */
    public function giveBack(User $user, string $ip): void
    {
        $countedAt = $this->countedAt;
        $this->countedAt = null;

        if ($countedAt === null) {
            return;
        }

        try {
            Cache::lock(self::LOCK, 10)->block(self::LOCK_WAIT_SECONDS, function () use ($user, $ip, $countedAt): void {
                foreach ($this->keys($user, $ip) as $key) {
                    $tries = $this->recent($key, $this->now());
                    $at = array_search($countedAt, $tries, true);

                    if ($at !== false) {
                        unset($tries[$at]);
                        $this->store($key, array_values($tries));
                    }
                }
            });
        } catch (LockTimeoutException) {
            // The try stays counted: a right password costs one try, never the lockout.
        }
    }

    /** "Too many tries. Try again in 15 minutes." (1 minute at the least). */
    public static function lockedMessage(int $retryAfterSeconds): string
    {
        $minutes = max(1, (int) ceil($retryAfterSeconds / 60));

        return 'Too many tries. Try again in '.$minutes.' '.($minutes === 1 ? 'minute' : 'minutes').'.';
    }

    /** Count the try, holding the lock; the seconds to wait when a key already has 5. */
    private function count(User $user, string $ip): ?int
    {
        $now = $this->now();
        $tries = [];
        $wait = null;

        foreach ($this->keys($user, $ip) as $key) {
            $tries[$key] = $this->recent($key, $now);
            $over = count($tries[$key]) - self::MAX_TRIES;

            if ($over >= 0) {
                $freesAt = $tries[$key][$over] + self::WINDOW_MINUTES * 60_000;
                $wait = max($wait ?? 0, (int) ceil(($freesAt - $now) / 1000));
            }
        }

        if ($wait !== null) {
            return max(1, $wait);
        }

        foreach ($tries as $key => $recent) {
            $this->store($key, [...$recent, $now]);
        }

        $this->countedAt = $now;

        return null;
    }

    /** @return list<int> the key's counted tries in the last 15 minutes, oldest first (milliseconds) */
    private function recent(string $key, int $now): array
    {
        $tries = Cache::get($key);
        $since = $now - self::WINDOW_MINUTES * 60_000;

        return is_array($tries) ? array_values(array_filter($tries, fn (mixed $at): bool => is_int($at) && $at > $since)) : [];
    }

    /** @param  list<int>  $tries */
    private function store(string $key, array $tries): void
    {
        Cache::put($key, $tries, now()->addMinutes(self::WINDOW_MINUTES + 1));
    }

    private function now(): int
    {
        return (int) now()->getPreciseTimestamp(3);
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
