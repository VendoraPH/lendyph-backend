<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckTokenExpiry
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        $timeout = config('auth.token_timeout', 30);

        if ($token && $timeout > 0 && $token->last_used_at &&
            $token->last_used_at->diffInMinutes(now()) > $timeout) {
            $token->delete();

            return response()->json(['message' => 'Session expired due to inactivity.'], 401);
        }

        return $next($request);
    }
}
