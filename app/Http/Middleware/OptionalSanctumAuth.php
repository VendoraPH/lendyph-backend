<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Best-effort Sanctum auth: if a Bearer token is present it is resolved and
 * the user is attached to the request; if no token (or an invalid one) is
 * present the request continues anonymously. Used for endpoints that expose
 * an authenticated and an anonymous path (e.g. public registration), where
 * the controller decides which branch to take based on `$request->user()`.
 */
class OptionalSanctumAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken()) {
            $user = Auth::guard('sanctum')->user();
            if ($user) {
                Auth::setUser($user);
            }
        }

        return $next($request);
    }
}
