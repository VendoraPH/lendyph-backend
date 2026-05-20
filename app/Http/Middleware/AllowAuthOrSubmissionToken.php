<?php

namespace App\Http\Middleware;

use App\Models\Borrower;
use App\Services\BorrowerSubmissionTokenService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate the request via Sanctum if a Bearer token is present, otherwise
 * fall back to verifying the `X-Submission-Token` header against the borrower
 * id in the route. Used by the public-registration upload endpoints where the
 * frontend posts photo + valid IDs against the borrower that was just created
 * anonymously. Auth wins when both are present.
 */
class AllowAuthOrSubmissionToken
{
    public function __construct(private BorrowerSubmissionTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Short-circuit when a user is already attached — e.g. by `actingAs()`
        // in tests, or by an upstream auth guard. This keeps today's operator
        // behavior intact regardless of how the user was authenticated.
        if ($request->user()) {
            return $next($request);
        }

        // Try Sanctum next — when a Bearer token is on the request, the
        // request must authenticate as a real user (today's behavior).
        if ($request->bearerToken()) {
            $user = Auth::guard('sanctum')->user();
            if (! $user) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
            Auth::setUser($user);

            return $next($request);
        }

        // Anonymous fallback — require a valid submission token bound to the
        // borrower being mutated.
        $token = $request->header('X-Submission-Token');
        if (! $token) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $borrower = $request->route('borrower');
        $borrowerId = $borrower instanceof Borrower ? $borrower->id : (int) $borrower;

        if (! $this->tokens->verify($token, $borrowerId)) {
            return response()->json(['message' => 'Invalid or expired submission token.'], 401);
        }

        // Mark the request as token-authenticated so controllers can skip the
        // user permission check.
        $request->attributes->set('submission_token_authenticated', true);

        return $next($request);
    }
}
