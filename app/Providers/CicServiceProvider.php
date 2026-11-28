<?php

namespace App\Providers;

use App\Services\Cic\CicSwitch;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * The CIC reporting module's wiring. Production refuses to boot with test
 * captures on (`CIC_TEST_CAPTURES`), as LoanOriginationServiceProvider refuses
 * the test-code mode: a test capture is dated today on any day, so it could
 * never be a real month-end figure.
 *
 * The `cic` limiter (security review S-M2): 30 requests a minute per staff
 * user on the CIC screens that build or validate a whole book (readiness,
 * records, one record, issues, and the submissions list and detail). The
 * 60-second live cache makes repeats cheap, so the limit leaves room for a
 * screen's several calls and its paging.
 */
class CicServiceProvider extends ServiceProvider
{
    public const PER_MINUTE = 30;

    /** Every CIC staff read shares this bucket in the `api` limiter (AppServiceProvider), apart from the rest of the app. */
    public const API_READS_PER_MINUTE = 60;

    public const TOO_MANY = 'Too many CIC requests. Wait a minute and try again.';

    public function boot(): void
    {
        if ($this->app->environment('production') && config('cic.test_captures') === true) {
            throw new RuntimeException(CicSwitch::PRODUCTION_REFUSAL);
        }

        RateLimiter::for('cic', fn (Request $request) => Limit::perMinute(self::PER_MINUTE)
            ->by('cic:'.($request->user()?->id ?: $request->ip()))
            ->response(self::tooMany(...)));
    }

    /** A CIC 429 in words, with when to try again. */
    public static function tooMany(Request $request, array $headers): JsonResponse
    {
        return response()->json([
            'message' => self::TOO_MANY,
            'retry_after' => (int) ($headers['Retry-After'] ?? 0),
        ], 429, $headers);
    }
}
