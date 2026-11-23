<?php

namespace App\Http\Middleware\PublicApply;

/**
 * The public application's middleware stack (origination decision 1), named
 * once so every `routes/api/public-apply/*.php` route uses it and the route
 * test can assert it: no-store, the server switch, the route's own limiter
 * (never only the shared `api` one), the link, and the session where one is
 * needed. No staff guard: a staff token counts for nothing here.
 */
final class PublicApplyStack
{
    /**
     * @param  string  $limiter  a `public-apply-*` limiter (LoanOriginationServiceProvider)
     * @param  ?string  $session  `intake`, `track`, `any`, `submit` or `logout` (RequireApplicationSession); null before the code step
     * @return list<string>
     */
    public static function for(string $limiter, ?string $session = null): array
    {
        return array_values(array_filter([
            PublicApplyNoStore::class,
            EnsureOriginationEnabled::class,
            'throttle:'.$limiter,
            ResolveApplicationLink::class,
            $session === null ? null : RequireApplicationSession::class.':'.$session,
        ]));
    }
}
