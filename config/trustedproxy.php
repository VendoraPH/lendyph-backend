<?php

/*
|--------------------------------------------------------------------------
| Trusted Proxies
|--------------------------------------------------------------------------
|
| The address(es) of a reverse proxy this API sits behind. Read by
| Illuminate\Http\Middleware\TrustProxies at request time (see its
| setTrustedProxyIpAddresses()); the header set it is allowed to honour is
| pinned to X-Forwarded-For alone in bootstrap/app.php.
|
| This ships EMPTY and empty is the correct value on today's fleet. The
| mechanism is here, correct and tested, but there is no safe address to put
| in it yet — see .env.example for the full reasoning and the proxy
| authentication design that has to land first.
|
| Why this lives in a config file rather than inline in bootstrap/app.php:
| the withMiddleware() callback runs when the HTTP kernel is *resolved*, which
| happens before Kernel::bootstrap() loads .env — and deploy.sh runs
| `artisan config:cache` on every box, after which .env is never read at
| runtime at all. env() outside a config file is therefore null in production,
| so sourcing the list there would have shipped a silent no-op. Cached config
| bakes this value in, which is the only thing that survives config:cache.
|
| Like config/cors.php, this is a per-deployment value: each instance sits
| behind its own frontend host. See .env.example.
|
*/

return [

    /*
     * array_filter, not a bare explode. An unset TRUSTED_PROXIES must resolve
     * to [] — the fail-closed default, identical to trusting no proxy at all —
     * and a bare explode would yield [''] instead, which TrustProxies happily
     * forwards to Request::setTrustedProxies() as a one-entry list.
     *
     * Exact addresses only, and only addresses nothing else on the host can
     * originate a request from. A trusted entry is permitted to dictate
     * $request->ip() for its own requests, so if REMOTE_ADDR is itself trusted
     * the caller simply names whatever address it likes and every per-IP
     * limiter in AppServiceProvider is bypassed.
     *
     * Never '*' or '**': TrustProxies expands those to 0.0.0.0/0, which trusts
     * the calling IP whatever it is. Avoid CIDR ranges too — when every entry
     * in the forwarded chain matches a trusted range, Symfony's
     * normalizeAndFilterClientIps() has nothing left to fall back to and
     * returns the LEFTMOST value in the chain, which is the attacker-supplied
     * one. A range is much easier to satisfy end-to-end than a single address.
     */
    'proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_PROXIES', ''))
    ))),

];
