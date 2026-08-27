<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
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
        // The previous note here said trustProxies() was deliberately NOT called
        // because there is "no CDN or load balancer in front" of this API. That
        // premise was wrong, and it is what caused the production bug this call
        // fixes: a browser never talks to this API directly. The Next.js
        // frontend rewrites every JSON call server-side (next.config.ts), so
        // REMOTE_ADDR on /api/borrowers is the frontend server, identically for
        // every applicant on the planet. `throttle:public-registration` keys on
        // $request->ip(), so it collapsed into ONE global bucket per deployment
        // and the second person to register in any 10-minute window got a 429.
        //
        // The trusted list itself lives in config/trustedproxy.php (env
        // TRUSTED_PROXIES), which TrustProxies reads at request time — env() is
        // unreadable here, and config:cache means it is unreadable at runtime
        // full stop.
        //
        // That list ships EMPTY, so the paragraph above describes what this
        // call makes possible rather than what it currently does. Trusting an
        // address lets that address choose $request->ip() for its own
        // requests, and this host also runs unrelated third-party apps, so its
        // public IP is not a safe entry — nor is loopback. Turning it on needs
        // the frontend to authenticate itself as the proxy with a
        // server-side-only shared secret; that is filed separately. The
        // limiter split in AppServiceProvider fixes the reported bug on its
        // own, without this. See config/trustedproxy.php.
        //
        // Only X-Forwarded-For is trusted, explicitly. The framework default
        // also trusts X-Forwarded-Host, and Next stamps x-forwarded-host on
        // every proxied request — honouring it would move $request->root() onto
        // the frontend host, so the signed links Borrower::photoUrl() mints
        // would point at a host with no /api/files/* route AND carry a
        // signature computed over the wrong URL.
        //
        // Spoofing does not work: the frontend vhost sets
        // `proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for`, which
        // APPENDS the real peer. Symfony resolves rightmost-untrusted, so a
        // browser-supplied `X-Forwarded-For: 6.6.6.6` becomes
        // "6.6.6.6, <browser-ip>" and the real browser IP is still what wins.
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR);

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
