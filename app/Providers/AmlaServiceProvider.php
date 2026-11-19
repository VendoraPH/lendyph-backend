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

    /**
     * What a caller holding any `amla:*` key gets on `amla.*`, per user, in a
     * bucket of its own (AMLA-B4 review). The shared `api` limiter (60 a
     * minute) stacks on top and binds first, so a figure above 60 would never
     * bite; 30 does, which caps a status-filtered worklist (it works out every
     * subject in scope) while leaving room for about ten AMLA screens a minute.
     */
    public const HOLDER_PER_MINUTE = 30;

    public function boot(): void
    {
        /*
         * Named on every `amla.*` route, on top of the shared `api` limiter,
         * which still caps everyone. A caller holding any AMLA key gets
         * HOLDER_PER_MINUTE; one holding none is cut to DENIED_PER_MINUTE.
         * Each has its own bucket. The token is already resolved:
         * authentication runs ahead of ThrottleRequests.
         */
        RateLimiter::for('amla', function (Request $request) {
            $caller = $request->user();

            // A member portal user's id is not a staff id, so the kind is in the key.
            $key = $caller === null ? 'ip:'.$request->ip() : class_basename($caller).':'.$caller->getKey();

            if ($caller instanceof User && $caller->canAny(AmlaAccess::PERMISSIONS)) {
                return Limit::perMinute(self::HOLDER_PER_MINUTE)->by('amla:user:'.$key);
            }

            return Limit::perMinute(self::DENIED_PER_MINUTE)->by('amla:denied:'.$key);
        });
    }
}
