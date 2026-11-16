<?php

namespace App\Services\Linda\Model;

use Closure;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * SDK middleware on every Linda model call (security review S1, S5), the
 * outermost one, so it sees each response before the SDK decides whether to
 * follow a redirect or retry.
 *
 * - A redirect (any 3xx) is refused with `redirect`, so the question is never
 *   re-sent to another host.
 * - The wait the server asks for before a retry (`retry-after-ms`, then
 *   `retry-after` in seconds or as an HTTP date, the order
 *   Anthropic\Core\BaseClient::retryDelay() reads them in) is capped at
 *   MAX_RETRY_AFTER_SECONDS, so a PHP worker waits at most that long between
 *   attempts. `x-should-retry` is left as sent, so the SDK still honours it.
 */
final class LindaHttpGuard
{
    public const MAX_RETRY_AFTER_SECONDS = 2;

    /**
     * @param  Closure(RequestInterface): ResponseInterface  $next
     *
     * @throws LindaModelUnavailable redirect
     */
    public function __invoke(RequestInterface $request, Closure $next): ResponseInterface
    {
        $response = $next($request);
        $status = $response->getStatusCode();

        if ($status >= 300 && $status < 400) {
            throw new LindaModelUnavailable('redirect');
        }

        return $this->capRetryAfter($response);
    }

    private function capRetryAfter(ResponseInterface $response): ResponseInterface
    {
        $milliseconds = $response->getHeaderLine('retry-after-ms');

        if (is_numeric($milliseconds) && (float) $milliseconds > self::MAX_RETRY_AFTER_SECONDS * 1000) {
            $response = $response->withHeader('retry-after-ms', (string) (self::MAX_RETRY_AFTER_SECONDS * 1000));
        }

        $seconds = $response->getHeaderLine('retry-after');

        if ($seconds === '') {
            return $response;
        }

        $wait = is_numeric($seconds)
            ? (float) $seconds
            : (($date = strtotime($seconds)) === false ? null : (float) ($date - time()));

        if ($wait === null || $wait > self::MAX_RETRY_AFTER_SECONDS) {
            return $response->withHeader('retry-after', (string) self::MAX_RETRY_AFTER_SECONDS);
        }

        return $response;
    }
}
