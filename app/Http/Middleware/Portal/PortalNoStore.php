<?php

namespace App\Http\Middleware\Portal;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every portal answer carries `Cache-Control: no-store` (portal design §1):
 * members' figures, codes and tokens are never kept by a browser or proxy.
 */
class PortalNoStore
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
