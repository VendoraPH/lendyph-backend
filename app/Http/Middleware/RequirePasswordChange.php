<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Api\AuthController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse every authenticated request from a user who is still holding a
 * password an administrator chose for them.
 *
 * The prompt for this belongs in the UI, but the *enforcement* cannot: a
 * frontend-only "please change your password" modal is defeated by one curl
 * against the same token, and the token is exactly what a reset hands out. So
 * the flag is checked here, on the way in, for every route in the authenticated
 * group.
 */
class RequirePasswordChange
{
    /**
     * The escape hatch: the only handlers a locked-out user may still reach.
     *
     *   GET  /api/auth/me              — so the client can see WHY it is locked
     *   POST /api/auth/change-password — the one action that clears the flag
     *   POST /api/auth/logout          — never trap someone in a session
     *
     * Keyed on the resolved controller action rather than on the request path.
     * Paths would have to hard-code the `api` prefix from bootstrap/app.php and,
     * worse, `/auth/me` is two routes: `GET` (allowed) and `PATCH` → updateMe,
     * an ordinary profile edit that must stay blocked. Action names separate
     * those two without a second method check and cannot drift if the URI moves.
     *
     * `refresh` is knowingly NOT here. Minting a fresh token is a normal
     * privilege and this state is meant to be short — a locked user who idles
     * past the token timeout should be made to log in again, not handed a new
     * 30 minutes. See the note in routes/api.php.
     */
    private const ALLOWED_ACTIONS = [
        AuthController::class.'@me',
        AuthController::class.'@changePassword',
        AuthController::class.'@logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // No user attached (the public-registration routes run this middleware
        // for anonymous callers too) or nothing to enforce: get out of the way.
        if (! $user?->must_change_password) {
            return $next($request);
        }

        if (in_array($request->route()?->getActionName(), self::ALLOWED_ACTIONS, true)) {
            return $next($request);
        }

        // 423 Locked, not 403. This API already answers 403 for "you lack the
        // permission for this", in the policy layer and in EnsureUserIsActive,
        // and the client has to tell the two apart: one is a dead end, the
        // other is a detour with a specific screen at the end of it. `code` is
        // the marker the frontend branches on — the message is copy and may be
        // reworded, so nothing should ever match on it.
        return response()->json([
            'message' => 'Your password was reset by an administrator. You must set a new password before continuing.',
            'code' => 'password_change_required',
            'must_change_password' => true,
        ], Response::HTTP_LOCKED);
    }
}
