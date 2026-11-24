<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Receipt share links (finish run round 2, decision 6): the public receipt's
 * own limiter, `receipt-share-view`.
 *
 * The route is anonymous, so the shared `api` limiter exempts it
 * (AppServiceProvider, by its `public-receipts.*` name and its path) and this
 * one meters it alone: a flood of views can't spend the bucket that the sign-in
 * page's branding and public registration share, and the reverse. Keyed on the
 * caller's address, which is each viewer's own once the frontend proves it is
 * the proxy (LENDYPH_PROXY_SECRET); without that every view shares the proxy's.
 */
class ReceiptShareServiceProvider extends ServiceProvider
{
    public const VIEWS_PER_MINUTE = 30;

    public function boot(): void
    {
        RateLimiter::for('receipt-share-view', fn (Request $request) => Limit::perMinute(self::VIEWS_PER_MINUTE)
            ->by('receipt-share:view:'.$request->ip())
            ->response(fn (Request $request, array $headers): JsonResponse => response()->json([
                'message' => 'Too many tries. Please wait a minute and try again.',
            ], 429, $headers)));
    }
}
