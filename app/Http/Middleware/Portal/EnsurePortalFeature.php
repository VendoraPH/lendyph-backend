<?php

namespace App\Http\Middleware\Portal;

use App\Services\Portal\PortalSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `portal.feature:<key>`: a part of the portal the cooperative switched off
 * answers 403 in words (portal design §5).
 */
class EnsurePortalFeature
{
    public const OFF = 'This part of the portal is turned off by your cooperative.';

    public function __construct(private readonly PortalSettings $settings) {}

    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        foreach ($features as $feature) {
            if (! $this->settings->featureOn($feature)) {
                return response()->json(['success' => false, 'message' => self::OFF, 'code' => 'feature_off'], 403);
            }
        }

        return $next($request);
    }
}
