<?php

namespace App\Http\Middleware;

use App\Services\TokenIdleWindow;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idle-session timeout: revoke a personal access token that has gone unused
 * for longer than `auth.token_timeout` minutes.
 *
 * This complements, rather than duplicates, the ABSOLUTE expiry Sanctum already
 * enforces. AuthController::login mints a token with `expires_at` set to
 * login + token_timeout for a normal sign-in, and login + 30 DAYS when the
 * caller asked to be remembered. For a normal sign-in the absolute expiry
 * always lands first, so this middleware is effectively a no-op there; the
 * remembered token is the one that genuinely needs a sliding idle check, and
 * before this it had none.
 *
 * Two independent bugs previously made the body unreachable, and both had to be
 * fixed for the timeout to exist at all:
 *
 *  1. The guard clause called `property_exists($token, 'last_used_at')`, which
 *     is FALSE. `last_used_at` is an Eloquent attribute served out of
 *     `$attributes` via __get, not a declared PHP property, so the condition
 *     short-circuited on every request and the body never ran.
 *
 *  2. Even without that, the value it wanted to read was already gone. Sanctum's
 *     Guard writes `last_used_at = now()` on EVERY authenticated request, before
 *     any middleware runs, so the elapsed time measured here was always ~0.
 *
 * The fix for (2) is TokenIdleWindow: a listener captures the stored value from
 * Sanctum's own TokenAuthenticated event, which fires immediately before the
 * overwrite. See App\Services\TokenIdleWindow.
 */
class CheckTokenExpiry
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        $timeout = (int) config('auth.token_timeout', 30);

        // Only real database-backed tokens carry an idle window. Session-based
        // callers and `actingAs()` in tests get a TransientToken, which has no
        // key and no `last_used_at` to measure.
        if ($timeout > 0 && $token instanceof PersonalAccessToken) {
            $idleSince = TokenIdleWindow::idleSince($request, $token);

            if ($idleSince && $idleSince->diffInMinutes(now()) > $timeout) {
                $token->delete();

                return response()->json(['message' => 'Session expired due to inactivity.'], 401);
            }
        }

        return $next($request);
    }
}
