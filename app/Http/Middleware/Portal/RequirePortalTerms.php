<?php

namespace App\Http\Middleware\Portal;

use App\Services\Portal\PortalTerms;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Terms re-acceptance (portal design §1): once a version published with
 * `requires_reacceptance` is out, every signed-in route except `me`, the
 * terms acceptance and sign-out answers 403 `terms_required` until the member
 * accepts it.
 */
class RequirePortalTerms
{
    /** Route names that stay open while terms are owed. */
    public const ALLOWED = ['portal.auth.me', 'portal.auth.terms', 'portal.auth.logout'];

    public function __construct(private readonly PortalTerms $terms) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs(...self::ALLOWED) && $this->terms->requiredFor($request->user())) {
            return response()->json([
                'success' => false,
                'message' => 'Please read and accept the updated terms to continue.',
                'code' => 'terms_required',
                'terms' => ['version' => $this->terms->current()?->version],
            ], 403);
        }

        return $next($request);
    }
}
