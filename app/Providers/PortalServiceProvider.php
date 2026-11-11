<?php

namespace App\Providers;

use App\Models\Borrower;
use App\Models\PortalUser;
use App\Services\Portal\Auth\MemberNumber;
use App\Services\Portal\PortalAccess;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

/**
 * The Member Portal's wiring (portal design §5, §7):
 *
 * - the portal rate limiters, every one keyed on the member (a hash of the
 *   member number before sign-in, the portal user after) or deployment-wide,
 *   never on the IP alone: every call arrives from the Next.js proxy, so
 *   `$request->ip()` is the same for everyone (§0, finding 8);
 * - revocation when a member's status changes (the Borrower listener);
 * - an expired portal token is deleted when it is presented, not merely
 *   refused;
 * - the portal observers, registered by convention: a class
 *   `App\Services\Portal\Observers\{Model}Observer` observes `App\Models\{Model}`,
 *   so the read and request slices add observers without editing lending or
 *   savings files.
 */
class PortalServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerRateLimiters();
        $this->registerRevocation();
        $this->registerExpiredTokenCleanup();
        $this->registerObservers();
    }

    private function registerRateLimiters(): void
    {
        $tooMany = fn (Request $request, array $headers): JsonResponse => response()->json([
            'success' => false,
            'message' => 'Too many attempts. Please wait a few minutes and try again.',
            'retry_after' => (int) ($headers['Retry-After'] ?? 0),
        ], 429, $headers);

        RateLimiter::for('portal-public', fn () => Limit::perMinute(120)->by('portal:public')->response($tooMany));

        RateLimiter::for('portal-login', fn (Request $request) => [
            Limit::perMinutes(15, 10)->by('portal:login:'.MemberNumber::hash($request->input('member_number')))->response($tooMany),
            Limit::perMinute(300)->by('portal:login:all')->response($tooMany),
        ]);

        // Verify carries the member number; the OTP and complete steps carry
        // the step token instead, which stands for the same member.
        RateLimiter::for('portal-codes', function (Request $request) use ($tooMany): array {
            $step = $request->input('step_token');
            $subject = is_string($step) && $step !== ''
                ? 'step:'.hash('sha256', $step)
                : 'member:'.MemberNumber::hash($request->input('member_number'));

            return [
                Limit::perMinutes(15, 10)->by('portal:codes:'.$subject)->response($tooMany),
                Limit::perMinute(120)->by('portal:codes:all')->response($tooMany),
            ];
        });

        RateLimiter::for('portal', fn (Request $request) => Limit::perMinute(120)->by('portal:user:'.self::portalUserKey($request))->response($tooMany));

        RateLimiter::for('portal-pdf', fn (Request $request) => Limit::perMinute(10)->by('portal:pdf:'.self::portalUserKey($request))->response($tooMany));

        RateLimiter::for('portal-writes', fn (Request $request) => [
            Limit::perMinute(10)->by('portal:writes:'.self::portalUserKey($request))->response($tooMany),
            Limit::perDay(50)->by('portal:writes:day:'.self::portalUserKey($request))->response($tooMany),
        ]);

        // Staff issuing codes: per staff user an hour, and per member a day
        // (the raw route id: this runs before the binding). CodeDesk counts
        // the codes themselves as well.
        RateLimiter::for('portal-admin-codes', fn (Request $request) => [
            Limit::perHour(30)->by('portal:admin-codes:staff:'.($request->user()?->getKey() ?? 'guest'))->response($tooMany),
            Limit::perDay(5)->by('portal:admin-codes:member:'.(string) $request->route('borrower'))->response($tooMany),
        ]);
    }

    private static function portalUserKey(Request $request): string
    {
        $user = $request->user();

        return $user instanceof PortalUser ? (string) $user->getKey() : 'none';
    }

    /**
     * A member's status change ends their portal sessions at once (and, for
     * withdrawn or deceased, turns access off). EnsurePortalMemberActive is
     * the backstop for bulk updates, which fire no model events.
     */
    private function registerRevocation(): void
    {
        Borrower::updated(function (Borrower $member): void {
            if (! $member->wasChanged('status')) {
                return;
            }

            app(PortalAccess::class)->memberStatusChanged($member, $member->getOriginal('status'), (string) $member->status);
        });
    }

    /**
     * Sanctum refuses an expired token but leaves it in the table. A portal
     * token past its `expires_at` is deleted as soon as it is presented; the
     * answer is unchanged (a 401). Staff tokens are untouched.
     */
    private function registerExpiredTokenCleanup(): void
    {
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid): bool {
            if (! $isValid && $token->tokenable_type === PortalUser::class
                && $token->expires_at !== null && $token->expires_at->isPast()) {
                $token->delete();
            }

            return $isValid;
        });
    }

    private function registerObservers(): void
    {
        foreach (glob(app_path('Services/Portal/Observers/*Observer.php')) ?: [] as $file) {
            $name = basename($file, 'Observer.php');
            $observer = 'App\\Services\\Portal\\Observers\\'.$name.'Observer';
            $model = 'App\\Models\\'.$name;

            if (class_exists($observer) && class_exists($model) && is_subclass_of($model, Model::class)) {
                $model::observe($observer);
            }
        }
    }
}
