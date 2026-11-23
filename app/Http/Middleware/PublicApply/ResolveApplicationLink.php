<?php

namespace App\Http\Middleware\PublicApply;

use App\Models\OriginationLink;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Finds the link a public route's `{token}` names, exactly (case included,
 * compared in constant time), and keeps it on the request as
 * `origination_link`. An unknown token answers 404. A closed link still
 * resolves: its landing page says why it is closed, and tracking works.
 */
class ResolveApplicationLink
{
    public const ATTRIBUTE = 'origination_link';

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->route('token');

        abort_unless(is_string($token) && strlen($token) === OriginationLink::TOKEN_LENGTH && ctype_alnum($token), 404);

        /** @var ?OriginationLink $link */
        $link = OriginationLink::query()->where('token', $token)->first();

        abort_unless($link !== null && hash_equals($link->token, $token), 404);

        $request->attributes->set(self::ATTRIBUTE, $link);

        return $next($request);
    }
}
