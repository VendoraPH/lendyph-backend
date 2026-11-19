<?php

namespace App\Http\Middleware\PublicApply;

use App\Services\LoanOrigination\OriginationSwitch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The server switch (LOAN_ORIGINATION_ENABLED, off by default): while it is
 * off every public application route answers 404, as an unknown address
 * would, before any link is looked up.
 */
class EnsureOriginationEnabled
{
    public function __construct(private readonly OriginationSwitch $switch) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->switch->enabled(), 404);

        return $next($request);
    }
}
