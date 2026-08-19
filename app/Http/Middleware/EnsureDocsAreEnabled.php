<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the l5-swagger routes behind an explicit per-deployment opt-in.
 *
 * One codebase serves every Lendyph deployment, so the docs cannot be gated on
 * `APP_ENV` — that value has drifted between instances before, and a box that
 * wrongly calls itself something else would silently publish its API surface.
 * An explicit flag defaulting to false fails closed instead: a deployment that
 * says nothing gets no docs.
 *
 * Answering 404 rather than 403 is deliberate. A 403 confirms the route exists;
 * on a deployment that has not opted in there is nothing to confirm.
 */
class EnsureDocsAreEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('l5-swagger.enabled', false), 404);

        return $next($request);
    }
}
