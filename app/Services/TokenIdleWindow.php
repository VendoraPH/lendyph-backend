<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Carries `personal_access_tokens.last_used_at` as it stood when the request
 * ARRIVED, from Sanctum's guard to CheckTokenExpiry, and moves it on (touch())
 * once the request has passed the limiters.
 *
 * Who writes the column (finish run round 7): Sanctum's own write is turned off
 * (`sanctum.last_used_at` false), because it ran while the caller was resolved,
 * ahead of every limiter, so a request the limiter then refused with 429 still
 * reset the idle timer. The idle checks (CheckTokenExpiry and
 * CheckPortalTokenExpiry) call touch() after a token passes, and they sort
 * after ThrottleRequests (bootstrap/app.php), so only a request that passes the
 * limiters counts as activity. The history below is why the value is captured
 * at all; with the write turned off it still guards the read against any other
 * write of the column during the request.
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
     * Record this request as the token's latest activity, as Sanctum's own
     * write did (Guard::updateLastUsedAt()), keeping the connection's
     * "records modified" state so a sticky read connection isn't switched
     * to the writer by this bookkeeping write.
     *
     * Only for a token Sanctum's guard resolved on THIS request (the one
     * capture() saw), which is exactly the set Sanctum used to write: a token
     * attached any other way (Sanctum::actingAs() in tests) is left alone.
     */
    public static function touch(Request $request, PersonalAccessToken $token): void
    {
        if ($request->attributes->get(self::TOKEN_ID) !== $token->getKey()) {
            return;
        }

        $connection = $token->getConnection();

        if (method_exists($connection, 'hasModifiedRecords') && method_exists($connection, 'setRecordModificationState')) {
            $hasModifiedRecords = $connection->hasModifiedRecords();
            $token->forceFill(['last_used_at' => now()])->save();
            $connection->setRecordModificationState($hasModifiedRecords);

            return;
        }

        $token->forceFill(['last_used_at' => now()])->save();
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
