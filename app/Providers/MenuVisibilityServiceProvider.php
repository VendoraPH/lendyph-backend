<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menu Visibility (finish run round 6): the administrator override's own
 * limiter, `menu-visibility-override`, on POST /menu-visibility/override.
 *
 * 5 wrong passwords in 15 minutes lock the override for the rest of that window
 * (it starts at the first wrong try), on two keys at once: the user, and the
 * caller's address. A right password is never counted (Limit::after() counts
 * only a 422 answer), and while locked even the right one is refused, before
 * it is checked. While TRUSTED_PROXIES is empty the address is the frontend
 * proxy's, so the address key is in effect the deployment's: five wrong tries
 * from anyone lock everyone out of the override for the window, which suits a
 * shared secret. The keys carry ids only and are hashed by ThrottleRequests;
 * the password never reaches them.
 */
class MenuVisibilityServiceProvider extends ServiceProvider
{
    public const WRONG_TRIES = 5;

    public const LOCK_MINUTES = 15;

    public function boot(): void
    {
        $wrongPassword = fn (Response $response): bool => $response->getStatusCode() === 422;

        $locked = fn (Request $request, array $headers): JsonResponse => response()->json([
            'message' => self::lockedMessage((int) ($headers['Retry-After'] ?? self::LOCK_MINUTES * 60)),
            'retry_after' => (int) ($headers['Retry-After'] ?? 0),
        ], 429, $headers);

        RateLimiter::for('menu-visibility-override', fn (Request $request) => [
            Limit::perMinutes(self::LOCK_MINUTES, self::WRONG_TRIES)
                ->by('menu-visibility-override:user:'.($request->user()?->getAuthIdentifier() ?? 'none'))
                ->after($wrongPassword)
                ->response($locked),
            Limit::perMinutes(self::LOCK_MINUTES, self::WRONG_TRIES)
                ->by('menu-visibility-override:ip:'.$request->ip())
                ->after($wrongPassword)
                ->response($locked),
        ]);
    }

    /** "Too many tries. Try again in 15 minutes." (1 minute at the least). */
    public static function lockedMessage(int $retryAfterSeconds): string
    {
        $minutes = max(1, (int) ceil($retryAfterSeconds / 60));

        return 'Too many tries. Try again in '.$minutes.' '.($minutes === 1 ? 'minute' : 'minutes').'.';
    }
}
