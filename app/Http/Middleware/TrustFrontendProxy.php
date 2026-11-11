<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

/**
 * Trusts the frontend's server-side proxy hop, but only when it proves itself.
 *
 * Every browser call reaches this API through the Next.js frontend, which
 * proxies /api/proxy/* from its own server. REMOTE_ADDR is therefore the
 * frontend box for every visitor. That box also runs unrelated apps, so
 * trusting its address (or loopback) through TRUSTED_PROXIES would let any of
 * them choose $request->ip() (see config/trustedproxy.php).
 *
 * Instead the frontend sends a server-only shared secret in the
 * X-Lendyph-Proxy header. When config('trustedproxy.proxy_secret') is set and
 * the header matches it exactly (hash_equals, so timing reveals nothing), the
 * calling address is trusted for X-Forwarded-For alone for this one request:
 * never X-Forwarded-Host, Proto, Port or Prefix, which would move the app's
 * host and break signed links. Symfony still resolves the rightmost untrusted
 * address, so a browser-supplied X-Forwarded-For is ignored: the frontend's
 * nginx appends the real peer after it.
 *
 * The secret is also accepted only from an address in
 * config('trustedproxy.proxy_sources'), the box the frontend runs on, so a
 * leaked secret is useless from anywhere else. An empty list accepts any
 * source. A listed entry that isn't a valid IP never matches, so a typo fails
 * closed.
 *
 * Anything else (no secret configured, a secret shorter than 32 characters, a
 * missing or wrong header, an unlisted source) falls through to the framework's TrustProxies
 * unchanged, which is exactly today's behaviour.
 *
 * The header is removed from the request either way, before anything after
 * this middleware runs, so no log, exception report or audit row can carry it.
 */
class TrustFrontendProxy extends TrustProxies
{
    public const HEADER = 'X-Lendyph-Proxy';

    /** The same header as PHP's $_SERVER key. */
    private const SERVER_KEY = 'HTTP_X_LENDYPH_PROXY';

    /** A shorter configured secret counts as unset: the feature stays off. */
    public const MIN_SECRET_LENGTH = 32;

    /**
     * @param  Closure(Request): mixed  $next
     */
    public function handle(Request $request, Closure $next)
    {
        $proven = $this->provesFrontendProxy($request);

        $this->forgetProxyHeader($request);

        if (! $proven) {
            return parent::handle($request, $next);
        }

        $request::setTrustedProxies(
            [(string) $request->server->get('REMOTE_ADDR')],
            Request::HEADER_X_FORWARDED_FOR,
        );

        return $next($request);
    }

    private function provesFrontendProxy(Request $request): bool
    {
        $secret = (string) config('trustedproxy.proxy_secret', '');

        if (strlen($secret) < self::MIN_SECRET_LENGTH) {
            return false;
        }

        $sent = $request->headers->get(self::HEADER);

        if (! is_string($sent) || $sent === '') {
            return false;
        }

        $remote = $request->server->get('REMOTE_ADDR');

        if (! is_string($remote) || filter_var($remote, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return $this->isAllowedSource($remote) && hash_equals($secret, $sent);
    }

    /**
     * Compares packed addresses, so one IPv6 address matches in any notation.
     */
    private function isAllowedSource(string $remote): bool
    {
        $sources = (array) config('trustedproxy.proxy_sources', []);

        if ($sources === []) {
            return true;
        }

        $packed = inet_pton($remote);

        foreach ($sources as $source) {
            if (is_string($source) && filter_var($source, FILTER_VALIDATE_IP) !== false && inet_pton($source) === $packed) {
                return true;
            }
        }

        return false;
    }

    private function forgetProxyHeader(Request $request): void
    {
        $request->headers->remove(self::HEADER);
        $request->server->remove(self::SERVER_KEY);
        unset($_SERVER[self::SERVER_KEY]);
    }
}
