<?php

namespace App\Http\Middleware;

use App\Services\MenuVisibility\MenuVisibility;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A module switched off in Settings → Menu Visibility (finish run round 6)
 * answers 404 on its own API, exactly as a route that doesn't exist, on staff,
 * member portal and public routes alike. Which routes are a module's own is
 * App\Services\MenuVisibility\MenuModules, matched against the matched route's
 * URI, so an encoded or doubled-slash path can't slip past it.
 *
 * It runs on every API route (the `api` group, bootstrap/app.php), after the
 * post-auth gates and ahead of route-model binding: an idle, deactivated or
 * locked sign-in still gets its own answer, and a hidden module's ids are never
 * looked up. Background jobs are unaffected.
 */
class BlockHiddenModules
{
    public function __construct(private readonly MenuVisibility $visibility) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->visibility->blocks($request)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return $next($request);
    }
}
