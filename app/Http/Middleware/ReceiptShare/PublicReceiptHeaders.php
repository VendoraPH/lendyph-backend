<?php

namespace App\Http\Middleware\ReceiptShare;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every answer of the public receipt route, a refusal or a 429 included, is
 * kept by no browser or proxy cache (`no-store`) and indexed by no search
 * engine: the address holds the link's token. It runs ahead of the limiters
 * and the switch (bootstrap/app.php), so it wraps their answers too.
 */
class PublicReceiptHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
