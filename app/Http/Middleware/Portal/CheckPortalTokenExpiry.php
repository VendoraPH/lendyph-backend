<?php

namespace App\Http\Middleware\Portal;

use App\Services\Portal\PortalSettings;
use App\Services\TokenIdleWindow;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * The portal's idle timeout (portal design §1): a token unused for longer
 * than `idle_minutes` is deleted and answers 401. Measured like the staff
 * CheckTokenExpiry, from the last use Sanctum saw before this request
 * (TokenIdleWindow). The absolute expiry is the token's own `expires_at`.
 */
class CheckPortalTokenExpiry
{
    public const IDLE = 'Your session ended after a period of inactivity. Please sign in again.';

    public function __construct(private readonly PortalSettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $idleSince = TokenIdleWindow::idleSince($request, $token);

            if ($idleSince && $idleSince->diffInMinutes(now()) > $this->settings->current()->idle_minutes) {
                $token->delete();

                return response()->json(['message' => self::IDLE, 'code' => 'session_idle'], 401);
            }
        }

        return $next($request);
    }
}
