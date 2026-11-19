<?php

namespace App\Services\LoanOrigination;

/**
 * The server's own loan origination switches (origination decision 17),
 * read from config only (config/loan_origination.php):
 *
 * - `enabled` (LOAN_ORIGINATION_ENABLED, off by default): while it is off no
 *   public link takes an application, whatever the settings say;
 * - the public address links are built on (LOAN_ORIGINATION_PUBLIC_URL). It
 *   counts only when it is exactly one of CORS_ALLOWED_ORIGINS (a trailing
 *   slash ignored), an https origin with no path, query or fragment (http
 *   only for localhost). Anything else leaves links without an address, with
 *   the reason in words, rather than stopping the API from booting.
 */
final class OriginationSwitch
{
    public const URL_NOT_SET = 'The public application address isn\'t set on the server yet, so links have no address.';

    public const URL_NOT_ALLOWED = 'The public application address must be one of the addresses the server accepts (CORS_ALLOWED_ORIGINS), so links have no address yet.';

    public const URL_MALFORMED = 'The public application address must be a plain https address with no path, so links have no address yet.';

    public function enabled(): bool
    {
        return config('loan_origination.enabled') === true;
    }

    public function publicUrl(): ?string
    {
        return $this->publicUrlProblem() === null ? $this->configuredUrl() : null;
    }

    public function publicUrlProblem(): ?string
    {
        $url = $this->configuredUrl();

        if ($url === null) {
            return self::URL_NOT_SET;
        }

        if (! self::isPlainOrigin($url)) {
            return self::URL_MALFORMED;
        }

        $allowed = array_map(fn ($origin) => rtrim((string) $origin, '/'), (array) config('cors.allowed_origins', []));

        return in_array($url, $allowed, true) ? null : self::URL_NOT_ALLOWED;
    }

    /** `<public url>/apply/<token>`, or null while there is no usable address. */
    public function linkUrl(string $token): ?string
    {
        $base = $this->publicUrl();

        return $base === null ? null : $base.'/apply/'.rawurlencode($token);
    }

    private function configuredUrl(): ?string
    {
        $url = config('loan_origination.public_url');

        return is_string($url) && trim($url) !== '' ? rtrim(trim($url), '/') : null;
    }

    private static function isPlainOrigin(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (isset($parts['path']) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        $local = in_array(strtolower($parts['host']), ['localhost', '127.0.0.1'], true);

        return $scheme === 'https' || ($scheme === 'http' && $local);
    }
}
