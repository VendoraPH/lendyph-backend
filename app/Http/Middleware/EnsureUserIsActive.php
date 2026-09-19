<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->status !== 'inactive') {
            return $next($request);
        }

        // Revoke the credential this request arrived on, so a deactivated
        // account cannot keep presenting a token that is still live in the
        // database. Best effort by design: currentAccessToken() is a
        // PersonalAccessToken for API-token auth, a TransientToken for
        // session-based (SPA) auth, and null on a non-Sanctum guard — only the
        // first has a row to delete, and calling delete() on either of the
        // others is a fatal Error, not a no-op. Same test as
        // CheckTokenExpiry and AuthController::changePassword.
        //
        // Nothing is lost when there is nothing to revoke: this check reads
        // `status` from the database on EVERY request, so a session caller is
        // refused just the same each time they come back.
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['message' => 'Your account has been deactivated.'], 403);
    }
}
