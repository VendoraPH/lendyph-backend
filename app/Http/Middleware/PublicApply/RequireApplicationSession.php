<?php

namespace App\Http\Middleware\PublicApply;

use App\Models\OriginationLink;
use App\Services\LoanOrigination\Intake\ApplicantSessions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The applicant's session (origination decision 8), from the
 * `X-Application-Session` header, on the link the route names. `intake`
 * routes take an `apply` or `resume` session, `track` routes a `track`
 * session, `any` either. Anything else answers 401 in words:
 * `session_required` with no header, `session_expired` for an unknown, ended,
 * idle or too old session, or one from another link.
 *
 * A staff bearer token counts for nothing here.
 */
class RequireApplicationSession
{
    public const SESSION = 'origination_session';

    public const APPLICATION = 'origination_application';

    public function __construct(private readonly ApplicantSessions $sessions) {}

    public function handle(Request $request, Closure $next, string $purpose = 'intake'): Response
    {
        $token = $request->header(ApplicantSessions::HEADER);

        if (! is_string($token) || $token === '') {
            return self::refuse(ApplicantSessions::REQUIRED, 'session_required');
        }

        /** @var OriginationLink $link */
        $link = $request->attributes->get(ResolveApplicationLink::ATTRIBUTE);
        $session = strlen($token) <= 128 ? $this->sessions->resume($link, $token) : null;

        if ($session === null || $session->application === null) {
            return self::refuse(ApplicantSessions::EXPIRED, 'session_expired');
        }

        $allowed = match ($purpose) {
            'track' => $session->isTracking(),
            'any' => true,
            default => ! $session->isTracking(),
        };

        if (! $allowed) {
            return self::refuse(ApplicantSessions::WRONG_PURPOSE, 'session_wrong_purpose');
        }

        $request->attributes->set(self::SESSION, $session);
        $request->attributes->set(self::APPLICATION, $session->application);

        return $next($request);
    }

    private static function refuse(string $message, string $code): Response
    {
        return response()->json(['success' => false, 'message' => $message, 'code' => $code], 401);
    }
}
