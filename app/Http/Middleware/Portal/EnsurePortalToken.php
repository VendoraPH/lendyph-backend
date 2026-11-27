<?php

namespace App\Http\Middleware\Portal;

use App\Models\PortalUser;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * A portal route takes a portal token and nothing else (portal design §5):
 * a personal access token that belongs to a PortalUser and carries the
 * `portal` ability. Sanctum's session path (a TransientToken for a signed-in
 * web session) and any staff token are refused with the same 401 the
 * framework gives a guest.
 */
class EnsurePortalToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (! $user instanceof PortalUser
            || ! $token instanceof PersonalAccessToken
            || $token->tokenable_type !== $user->getMorphClass()
            || (int) $token->tokenable_id !== (int) $user->getKey()
            || ! in_array(PortalUser::ABILITY, (array) $token->abilities, true)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
