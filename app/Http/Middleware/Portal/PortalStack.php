<?php

namespace App\Http\Middleware\Portal;

/**
 * The Member Portal's middleware stacks (portal design §5), named once so
 * every `routes/api/portal/*.php` file uses the same ones and the stack test
 * can assert them.
 */
final class PortalStack
{
    /**
     * Public portal routes (no token). Each also names its own limiter. The
     * sign-in route checks the portal switch itself, so the refused attempt
     * is in the member's history; `GET /portal/config` answers while the
     * portal is off (with `enabled: false`).
     *
     * @return list<string>
     */
    public static function public(): array
    {
        return [PortalNoStore::class, EnsurePortalEnabled::class];
    }

    /**
     * Every signed-in portal route, in this order: a portal token only (never
     * a staff token or a session), the idle timeout, the member still active
     * with access on, the terms accepted, `no-store`, then the portal limiter.
     * Feature switches are added per group (`portal.feature:<key>`).
     *
     * @return list<string>
     */
    public static function signedIn(): array
    {
        return [
            'auth:portal',
            EnsurePortalToken::class,
            CheckPortalTokenExpiry::class,
            EnsurePortalMemberActive::class,
            RequirePortalTerms::class,
            PortalNoStore::class,
            'throttle:portal',
        ];
    }
}
