<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // NOTE: trustProxies() is deliberately NOT called.
        //
        // These five deployments terminate TLS in nginx on the same host and
        // hand to php-fpm over a unix socket, with no CDN or load balancer in
        // front — so REMOTE_ADDR is already the real client. An empty trusted
        // proxy list is also what makes X-Forwarded-For unspoofable here.
        //
        // That matters because `throttle:public-registration` (5 per 10 min) is
        // the only thing standing in front of the anonymous borrower-registration
        // and KYC-upload routes. **If a CDN or load balancer is ever put in front
        // of this API, trustProxies() must be added in the same change** — every
        // request would otherwise arrive from the proxy's IP and the limiter would
        // silently collapse into a single global bucket for all callers.
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
        ]);

        $middleware->api(prepend: [
            SecurityHeaders::class,
            ThrottleRequests::class.':api',
        ]);

        $middleware->api(append: [
            EnsureFrontendRequestsAreStateful::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
