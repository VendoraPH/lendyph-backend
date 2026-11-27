<?php

namespace App\Http\Middleware;

use App\Services\TokenIdleWindow;
use App\Services\TokenTimeout;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idle-session timeout: revoke a personal access token that has gone unused
 * for longer than `auth.token_timeout` minutes, and record a request that
 * passes as the token's latest activity.
 *
 * This complements, rather than duplicates, the ABSOLUTE expiry Sanctum already
 * enforces. Since "Remember me" was removed (finish run round 7) every sign-in
 * and every refresh gets a token that expires `token_timeout` minutes after it
 * was issued, so for those the absolute expiry lands first and the idle check
 * never fires on its own. It is kept, not removed, because:
 *  - remembered tokens issued before round 7 were cut to the session length by
 *    the 2026_11_26_830000 migration, but one last used long ago is still
 *    ended here at once;
 *  - it is the one place a longer-lived token (one minted by hand or by a
 *    future change) is still held to the idle rule;
 *  - it is where the idle clock moves (TokenIdleWindow::touch()).
 *
 * Only a request that reaches this middleware counts as activity. It sorts
 * after ThrottleRequests (bootstrap/app.php), and Sanctum's own `last_used_at`
 * write is turned off (config/sanctum.php), so a request refused with 429 no
 * longer resets the idle timer (round 7, found in round 6). Refusals after it
 * (403, 404, 422, 423) still count, as they did.
 *
 * Two independent bugs once made the body unreachable, both fixed:
 *
 *  1. The guard clause called `property_exists($token, 'last_used_at')`, which
 *     is FALSE. `last_used_at` is an Eloquent attribute served out of
 *     `$attributes` via __get, not a declared PHP property, so the condition
 *     short-circuited on every request and the body never ran.
 *
 *  2. Sanctum's Guard wrote `last_used_at = now()` on every authenticated
 *     request, before any middleware ran, so the elapsed time measured here
 *     was always ~0. TokenIdleWindow captures the stored value from Sanctum's
 *     TokenAuthenticated event, which fires immediately before that write.
 */
class CheckTokenExpiry
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        $timeout = TokenTimeout::minutes();

        // Only real database-backed tokens carry an idle window. Session-based
        // callers and `actingAs()` in tests get a TransientToken, which has no
        // key and no `last_used_at` to measure.
        if ($token instanceof PersonalAccessToken) {
            $idleSince = TokenIdleWindow::idleSince($request, $token);

            if ($idleSince && $idleSince->diffInMinutes(now()) > $timeout) {
                $token->delete();

                return response()->json(['message' => 'Session expired due to inactivity.'], 401);
            }

            TokenIdleWindow::touch($request, $token);
        }

        return $next($request);
    }
}
