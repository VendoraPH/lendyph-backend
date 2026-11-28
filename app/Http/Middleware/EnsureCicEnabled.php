<?php

namespace App\Http\Middleware;

use App\Services\Cic\CicSwitch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CIC reporting's backend switch (`CIC_ENABLED`, off by default): while it is
 * off every `/cic` route answers 404, exactly as a route that doesn't exist and
 * as a hidden module does (BlockHiddenModules), so the API never tells anyone
 * the module is there. Required on the whole routes/api/cic/ group in
 * routes/api.php, behind the authenticated group's gates and ahead of
 * route-model binding (bootstrap/app.php), so no CIC id is ever looked up while
 * it is off.
 */
class EnsureCicEnabled
{
    public function __construct(private readonly CicSwitch $switch) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->switch->enabled()) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return $next($request);
    }
}
