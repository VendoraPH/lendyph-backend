<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\Portal\PortalTestHelpers;
use Tests\TestCase;

/**
 * A "too many requests" answer is not activity (finish run round 7, found in
 * round 6). Sanctum's guard used to write `last_used_at = now()` while
 * resolving the caller, ahead of every limiter, so a request the limiter then
 * refused with 429 still reset the idle timer, and a client could keep an idle
 * sign-in alive by sending requests that were all refused. The idle clock is
 * now moved only by the idle check itself (CheckTokenExpiry, and
 * CheckPortalTokenExpiry for the member portal), which runs after the
 * limiters, so only a request that passes them counts.
 */
class ThrottledRequestIdleTimerTest extends TestCase
{
    use PortalTestHelpers;

    private bool $throttled = false;

    /**
     * While `throttled`, the limiter answers with a bucket of one that is
     * already spent (as ThrottleRequests keys it), so the request is refused
     * with 429 by the real middleware; otherwise it lets everything through.
     */
    private function throttleWhenAsked(string $limiter): void
    {
        RateLimiter::for($limiter, fn () => $this->throttled ? Limit::perMinute(1)->by('round7:throttled') : Limit::none());
    }

    /** Turn the throttle on, its one-request bucket spent from now. */
    private function throttle(string $limiter): void
    {
        $this->throttled = true;
        RateLimiter::hit(md5($limiter.'round7:throttled'), 60);
    }

    /**
     * A token with a far-off expiry, so the idle window (30 minutes), not the
     * absolute expiry, is what ends it.
     *
     * @return array{0: string, 1: PersonalAccessToken}
     */
    private function staffTokenIdleFor(int $minutes): array
    {
        $new = User::where('username', 'super_admin')->firstOrFail()->createToken('auth-token', ['*'], now()->addDays(1));
        $new->accessToken->forceFill(['created_at' => now()->subHour(), 'last_used_at' => now()->subMinutes($minutes)])->save();

        return [$new->plainTextToken, $new->accessToken];
    }

    private function staffGet(string $plain, string $uri = '/api/auth/me'): TestResponse
    {
        Auth::forgetGuards();
        $this->app['auth']->forgetGuards();

        return $this->withToken($plain)->getJson($uri);
    }

    public function test_a_staff_request_refused_with_429_does_not_reset_the_idle_timer(): void
    {
        $this->throttleWhenAsked('api');
        [$plain, $token] = $this->staffTokenIdleFor(20);
        $lastUsed = $token->fresh()->last_used_at->toDateTimeString();

        $this->throttle('api');
        $this->staffGet($plain)->assertStatus(429);
        $this->assertSame($lastUsed, $token->fresh()->last_used_at->toDateTimeString(), 'A 429 moved the idle clock.');

        $this->throttled = false;
        $this->travel(11)->minutes();
        $this->staffGet($plain)->assertUnauthorized()->assertJson(['message' => 'Session expired due to inactivity.']);
        $this->assertNull(PersonalAccessToken::find($token->id));
    }

    public function test_a_staff_request_that_passes_the_limiters_still_resets_it(): void
    {
        $this->throttleWhenAsked('api');
        [$plain, $token] = $this->staffTokenIdleFor(20);

        $this->staffGet($plain)->assertOk();
        $this->assertLessThan(1, $token->fresh()->last_used_at->diffInMinutes(now()));

        $this->travel(20)->minutes();
        $this->staffGet($plain)->assertOk();
    }

    public function test_a_refused_answer_after_the_limiters_still_counts_as_activity(): void
    {
        $this->throttleWhenAsked('api');
        [$plain, $token] = $this->staffTokenIdleFor(20);

        $this->staffGet($plain, '/api/loans/999999999')->assertNotFound();

        $this->assertLessThan(1, $token->fresh()->last_used_at->diffInMinutes(now()));
    }

    public function test_a_member_portal_request_refused_with_429_does_not_reset_the_idle_timer(): void
    {
        $this->enablePortal();
        $this->throttleWhenAsked('portal');
        $portalUser = $this->portalMember();
        $token = $this->portalToken($portalUser);

        $this->portalJson('GET', '/api/portal/auth/me', [], $token)->assertOk();

        $this->travel(10)->minutes();
        $this->throttle('portal');
        $this->portalJson('GET', '/api/portal/auth/me', [], $token)->assertStatus(429);

        $this->throttled = false;
        $this->travel(6)->minutes();
        $this->portalJson('GET', '/api/portal/auth/me', [], $token)->assertStatus(401)->assertJsonPath('code', 'session_idle');
        $this->assertSame(0, $portalUser->tokens()->count());
    }

    public function test_a_member_portal_request_that_passes_the_limiters_still_resets_it(): void
    {
        $this->enablePortal();
        $this->throttleWhenAsked('portal');
        $portalUser = $this->portalMember();
        $token = $this->portalToken($portalUser);

        $this->portalJson('GET', '/api/portal/auth/me', [], $token)->assertOk();
        $this->travel(10)->minutes();
        $this->portalJson('GET', '/api/portal/auth/me', [], $token)->assertOk();
        $this->travel(10)->minutes();
        $this->portalJson('GET', '/api/portal/auth/me', [], $token)->assertOk();
    }
}
