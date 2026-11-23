<?php

namespace App\Providers;

use App\Models\OriginationApplication;
use App\Services\LoanOrigination\Files\ClamAvScanner;
use App\Services\LoanOrigination\Files\FileScanner;
use App\Services\LoanOrigination\Files\MisconfiguredScanner;
use App\Services\LoanOrigination\Files\NoScanner;
use App\Services\LoanOrigination\Intake\ApplicantSessions;
use App\Services\LoanOrigination\Intake\TestCodes;
use App\Support\PhilippineMobile;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * The loan origination module's wiring (Phase 6): the public application
 * (routes/api/public-apply/) and the staff side. Its rate limiters go here,
 * never in AppServiceProvider, as the portal's go in PortalServiceProvider.
 *
 * The upload scanner is chosen by `loan_origination.scanner.driver`, read
 * without regard to case or spaces: `none` (the default, or blank; files are
 * kept as "Not scanned") or `clamav`. Any other value is a misconfiguration,
 * never "no scanner": uploads are refused (503) until it is fixed, and the
 * settings screen says so.
 *
 * The public application's limiters (origination decision 2), every one
 * named `public-apply-*` and used only by routes/api/public-apply/:
 *
 * - `public-apply-read`: 60 a minute per IP (the landing page, the draft,
 *   tracking);
 * - `public-apply-codes`: 1 a minute and 5 an hour per mobile, 10 an hour per
 *   IP (the deployment's daily cap is counted in ApplicantOtp);
 * - `public-apply-verify`: 10 in 15 minutes per mobile and 30 an hour per IP,
 *   on top of each code's own 5 tries;
 * - `public-apply-drafts`: 60 a minute per session and 120 per IP;
 * - `public-apply-estimate`: 30 a minute per session (the `calculator`
 *   limiter's figure, keyed on the session instead of a user) and 60 per IP;
 * - `public-apply-uploads`: 30 an hour per session and 60 per IP (the file
 *   store also caps an application at 20 files);
 * - `public-apply-submit`: 10 a minute per session and 20 per IP;
 * - `public-apply-logout`: 10 a minute per session and 30 per IP.
 *
 * The session header is read before it is checked, so every session-keyed
 * limiter also has a per-IP limit: a new random header each time is still
 * metered.
 *
 * Mobiles are keyed by their keyed hash and sessions by their SHA-256, never
 * as typed. IP keys rely on TrustFrontendProxy for the visitor's address;
 * without its secret every visitor would share the frontend's, so links stay
 * closed until it is set (PublicPortal::NOT_READY).
 *
 * Production refuses to boot with the test-code mode on (origination
 * decision 7, risk 1).
 */
class LoanOriginationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FileScanner::class, fn (): FileScanner => match (strtolower(trim((string) config('loan_origination.scanner.driver', 'none')))) {
            '', 'none' => new NoScanner,
            'clamav' => ClamAvScanner::fromConfig(),
            default => new MisconfiguredScanner,
        });
    }

    public function boot(): void
    {
        if ($this->app->environment('production') && config('loan_origination_intake.otp.test_codes') === true) {
            throw new RuntimeException(TestCodes::PRODUCTION_REFUSAL);
        }

        $this->registerPublicApplyLimiters();
    }

    private function registerPublicApplyLimiters(): void
    {
        $tooMany = fn (Request $request, array $headers): JsonResponse => response()->json([
            'success' => false,
            'message' => 'Too many attempts. Please wait a moment and try again.',
            'retry_after' => (int) ($headers['Retry-After'] ?? 0),
        ], 429, $headers);

        $ip = fn (Request $request): string => (string) $request->ip();

        RateLimiter::for('public-apply-read', fn (Request $request) => Limit::perMinute(60)->by('public-apply:read:'.$ip($request))->response($tooMany));

        RateLimiter::for('public-apply-codes', fn (Request $request) => [
            Limit::perMinute(1)->by('public-apply:codes:minute:'.self::mobileKey($request))->response($tooMany),
            Limit::perHour(5)->by('public-apply:codes:hour:'.self::mobileKey($request))->response($tooMany),
            Limit::perHour(10)->by('public-apply:codes:ip:'.$ip($request))->response($tooMany),
        ]);

        RateLimiter::for('public-apply-verify', fn (Request $request) => [
            Limit::perMinutes(15, 10)->by('public-apply:verify:'.self::mobileKey($request))->response($tooMany),
            Limit::perHour(30)->by('public-apply:verify:ip:'.$ip($request))->response($tooMany),
        ]);

        RateLimiter::for('public-apply-drafts', fn (Request $request) => [
            Limit::perMinute(60)->by('public-apply:drafts:'.self::sessionKey($request))->response($tooMany),
            Limit::perMinute(120)->by('public-apply:drafts:ip:'.$ip($request))->response($tooMany),
        ]);

        RateLimiter::for('public-apply-estimate', fn (Request $request) => [
            Limit::perMinute(30)->by('public-apply:estimate:'.self::sessionKey($request))->response($tooMany),
            Limit::perMinute(60)->by('public-apply:estimate:ip:'.$ip($request))->response($tooMany),
        ]);

        RateLimiter::for('public-apply-uploads', fn (Request $request) => [
            Limit::perHour(30)->by('public-apply:uploads:'.self::sessionKey($request))->response($tooMany),
            Limit::perHour(60)->by('public-apply:uploads:ip:'.$ip($request))->response($tooMany),
        ]);

        RateLimiter::for('public-apply-submit', fn (Request $request) => [
            Limit::perMinute(10)->by('public-apply:submit:'.self::sessionKey($request))->response($tooMany),
            Limit::perMinute(20)->by('public-apply:submit:ip:'.$ip($request))->response($tooMany),
        ]);

        RateLimiter::for('public-apply-logout', fn (Request $request) => [
            Limit::perMinute(10)->by('public-apply:logout:'.self::sessionKey($request))->response($tooMany),
            Limit::perMinute(30)->by('public-apply:logout:ip:'.$ip($request))->response($tooMany),
        ]);
    }

    /** The mobile asked about, as its keyed hash; every unreadable one shares one key. */
    private static function mobileKey(Request $request): string
    {
        $mobile = $request->input('mobile');
        $normalised = is_string($mobile) ? PhilippineMobile::normalise($mobile) : null;

        return $normalised === null ? 'unreadable' : OriginationApplication::mobileHash($normalised);
    }

    /** The session header as its SHA-256, else the IP. */
    private static function sessionKey(Request $request): string
    {
        $token = $request->header(ApplicantSessions::HEADER);

        return is_string($token) && $token !== '' ? 'session:'.hash('sha256', $token) : 'ip:'.$request->ip();
    }
}
