<?php

namespace App\Http\Middleware\PublicApply;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every public application answer carries `Cache-Control: no-store`: drafts,
 * codes and session tokens are never kept by a browser or a proxy.
 */
class PublicApplyNoStore
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
