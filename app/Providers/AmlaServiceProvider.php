<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Amla\Foundation\AmlaAccess;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * The AMLA module's wiring (Phase 6). Its rate limiters go here, never in
 * AppServiceProvider, as the portal's go in PortalServiceProvider.
 */
class AmlaServiceProvider extends ServiceProvider
{
    /**
     * What a caller holding no `amla:*` key gets on `amla.*`: every AMLA route
     * refuses them, and each refusal writes an AMLA log row that is kept
     * forever, so they get their own small bucket rather than the shared
     * 60 a minute. The same number and shape as the `users.*` refusals in
     * AppServiceProvider.
     */
    public const DENIED_PER_MINUTE = 10;

    public function boot(): void
    {
        /*
         * Named on every `amla.*` route, on top of the shared `api` limiter,
         * which still caps everyone. A caller holding any AMLA key adds no
         * limit here, so nobody's budget grows; one holding none is cut to
         * DENIED_PER_MINUTE in a bucket of its own. The token is already
         * resolved: authentication runs ahead of ThrottleRequests.
         */
        RateLimiter::for('amla', function (Request $request) {
            $caller = $request->user();

            if ($caller instanceof User && $caller->canAny(AmlaAccess::PERMISSIONS)) {
                return Limit::none();
            }

            // A member portal user's id is not a staff id, so the kind is in the key.
            $key = $caller === null ? 'ip:'.$request->ip() : class_basename($caller).':'.$caller->getKey();

            return Limit::perMinute(self::DENIED_PER_MINUTE)->by('amla:denied:'.$key);
        });
    }
}
