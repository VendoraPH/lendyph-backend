<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Carries `personal_access_tokens.last_used_at` as it stood when the request
 * ARRIVED, from Sanctum's guard to CheckTokenExpiry.
 *
 * This exists because of an ordering problem no middleware can work around.
 * Sanctum's Guard does exactly this, in this order, while resolving the caller
 * (vendor/laravel/sanctum/src/Guard.php):
 *
 *     event(new TokenAuthenticated($accessToken));   // still the stored value
 *     $this->updateLastUsedAt($accessToken);         // forceFill(now())->save()
 *
 * By the time ANY middleware runs, the column already says `now()`, so an idle
 * check that reads it always measures zero elapsed time. The event is the last
 * moment the stored value is observable, so a listener in AppServiceProvider
 * hands it here and the middleware reads it back.
 *
 * The value rides on the Request's attribute bag rather than in a container
 * singleton, and that is deliberate. A singleton is request-scoped in
 * production — one container per request — but NOT under Laravel's HTTP test
 * harness, which reuses one application across several requests. A captured
 * timestamp that outlived its request would be read against a later `now()` and
 * would log a perfectly active operator out. A Request cannot outlive itself,
 * so binding to it removes that failure mode by construction rather than by
 * remembering to clear something.
 *
 * The token id is stored alongside the timestamp so a value can never be read
 * for a different token than the one it was captured from.
 */
final class TokenIdleWindow
{
    private const SINCE = 'sanctum.idle_since';

    private const TOKEN_ID = 'sanctum.idle_token_id';

    /**
     * Record what the token's `last_used_at` said, before Sanctum rewrites it.
     */
    public static function capture(Request $request, PersonalAccessToken $token): void
    {
        $request->attributes->set(self::TOKEN_ID, $token->getKey());
        $request->attributes->set(self::SINCE, $token->last_used_at);
    }

    /**
     * The moment this token was last exercised, as of the start of the request.
     *
     * Falls back to `created_at` when the token has never been used — the state
     * Sanctum leaves a freshly minted token in — because a token issued and
     * then abandoned is idle from the moment it was issued.
     *
     * Null when nothing was captured for THIS token, which means the caller was
     * not resolved through Sanctum's guard on this request. There is no
     * trustworthy idle measurement to make in that case, so the absolute
     * `expires_at` is left to do the work alone.
     */
    public static function idleSince(Request $request, PersonalAccessToken $token): ?Carbon
    {
        if ($request->attributes->get(self::TOKEN_ID) !== $token->getKey()) {
            return null;
        }

        return $request->attributes->get(self::SINCE) ?? $token->created_at;
    }
}
