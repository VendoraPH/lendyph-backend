<?php

use App\Http\Middleware\AllowAuthOrSubmissionToken;
use App\Http\Middleware\CheckTokenExpiry;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\OptionalSanctumAuth;
use App\Http\Middleware\RequirePasswordChange;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
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

        // Keep the optional-auth middleware AHEAD of ThrottleRequests.
        //
        // routes/api.php declares the public-registration stacks as
        // [OptionalSanctumAuth, throttle:..., ...] precisely so the limiter can
        // see $request->user() and hand operators Limit::none(). Declaring it
        // is not enough: Router::resolveMiddleware() runs the gathered stack
        // through SortedMiddleware, which reorders by the framework's priority
        // list. ThrottleRequests sits at priority 7 and SubstituteBindings at
        // 10, and the api group contributes SubstituteBindings ahead of the
        // route's own middleware — so the sorter hoists `throttle:...` above
        // SubstituteBindings and, in doing so, steps straight over
        // OptionalSanctumAuth, which is not in the priority list at all and is
        // therefore never considered.
        //
        // The observable effect was that BOTH registration limiters ran with a
        // null $request->user(): the `Limit::none()` operator exemption in
        // AppServiceProvider could never fire, so authenticated staff were
        // metered on the anonymous per-IP buckets. Naming these two here puts
        // them in the priority list immediately before ThrottleRequests, which
        // is what makes the declared order actually survive the sort.
        //
        // This does not disturb the auth:sanctum group: Authenticate already
        // outranks ThrottleRequests via the AuthenticatesRequests contract at
        // priority 6.
        $middleware->prependToPriorityList(ThrottleRequests::class, OptionalSanctumAuth::class);
        $middleware->prependToPriorityList(ThrottleRequests::class, AllowAuthOrSubmissionToken::class);

        // Keep the three post-auth gates AHEAD of SubstituteBindings.
        //
        // They were behind it, and that was a user-existence oracle. The cause
        // is not that binding is "hoisted above" them — it is not moved at all.
        // The `api` group SUPPLIES SubstituteBindings, and a group's middleware
        // is gathered outermost, ahead of anything the route itself declares;
        // the three gates are declared on the nested group in routes/api.php.
        // So binding starts in front on every one of these routes. What made
        // that position UNRECOVERABLE is their absence from this list: an
        // unlisted middleware keeps whatever index it was gathered at, and
        // listed middleware simply step over it. SortedMiddleware never had a
        // reason to consider them.
        //
        // The observable defect: for a caller whose token is live but flagged
        // `must_change_password`, binding answered 404 for an id that does not
        // exist while a live id got as far as RequirePasswordChange and came
        // back 423. RequirePasswordChange does not revoke the token, so the
        // probe is repeatable — walk the sequential ids and the 423s are the
        // real staff accounts. (EnsureUserIsActive and CheckTokenExpiry split
        // the same way, 403/404 and 401/404, but both delete the token, so
        // those are one shot each.)
        //
        // Position is deliberate. These go AFTER both throttle entries so the
        // six named limiters in AppServiceProvider keep seeing exactly the
        // $request->user() they see today — the two prepends above exist to
        // populate it, and nothing here may reorder that. Naming the gates
        // immediately before SubstituteBindings is the smallest move that
        // reaches in front of binding without crossing the meter.
        //
        // Order of these three calls is the order they end up in. Each call is
        // resolved against the CURRENT list and splices its middleware at the
        // index SubstituteBindings occupies at that moment, which pushes
        // binding one to the right — so the first call lands leftmost and the
        // last lands nearest to binding. Lines 86-87 read the same way:
        // OptionalSanctumAuth was named first and sorts first. The route-level
        // order (expiry, then active, then password) is preserved by declaring
        // them here in that same order, and routes/api.php explains why it
        // matters: a deactivated account must be turned away, not sent to a
        // change-password screen that would let it back in.
        //
        // Consequence worth knowing before something depends on it: a locked
        // user's request to a bound route is now refused BEFORE the model is
        // resolved. Nothing today reads a bound model from inside these three —
        // RequirePasswordChange keys on $request->route()?->getActionName(),
        // which comes off the route DEFINITION at match time, and its three
        // allowlisted actions take no URI parameter. But a future gate added
        // here that called $request->route('user') would get the raw string
        // from the URL, not a User, and would fail silently rather than loudly.
        //
        // Asserted on a real gathered stack in UserRouteEnumerationTest.
        $middleware->prependToPriorityList(SubstituteBindings::class, CheckTokenExpiry::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureUserIsActive::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, RequirePasswordChange::class);

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
