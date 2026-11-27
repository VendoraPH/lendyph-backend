<?php

namespace App\Http\Middleware\Portal;

use App\Services\Portal\Auth\PortalLogin;
use App\Services\Portal\PortalSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The backend portal switch (portal design §1), off by default: while it is
 * off every public portal route answers 503 `portal_off`.
 */
class EnsurePortalEnabled
{
    public function __construct(private readonly PortalSettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->settings->enabled()) {
            return response()->json(['success' => false, 'message' => PortalLogin::PORTAL_OFF, 'code' => 'portal_off'], 503);
        }

        return $next($request);
    }
}
