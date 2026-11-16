<?php

namespace App\Http\Middleware;

use App\Services\Linda\LindaHttpError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Linda's backend switch (`LINDA_ENABLED`, off by default): while it is off,
 * `POST /linda/chat` answers 501 `linda_off` to every signed-in caller. The
 * `linda` limiter counts nothing while it is off, so turning Linda on never
 * starts anyone part-way through their minute or day. Settings → Linda is not
 * behind it.
 */
class EnsureLindaEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('linda.enabled')) {
            return LindaHttpError::lindaOff()->toResponse();
        }

        return $next($request);
    }
}
