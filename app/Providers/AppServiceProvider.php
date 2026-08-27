<?php

namespace App\Providers;

use App\Services\BorrowerSubmissionTokenService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Signed file reads allowed per caller per minute.
     *
     * A borrower list renders one <img> per row, so a single page view bursts
     * up to ~100 GET /api/files/... in a second or two (production logged 613
     * from one address). Those requests carry no Authorization header — an
     * <img> cannot send one — so they key by IP and used to spend the shared
     * 60/min `api` budget, and admins got a 429 storm mid-render. 300 covers
     * three full list renders a minute; each request still has to present an
     * unexpired signature minted for an already-authorised caller.
     */
    private const FILE_READS_PER_MINUTE = 300;

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Super-admin bypass — developer-side super_admin role gets all
        // permissions automatically. Client-side "admin" no longer bypasses
        // here; instead, the RoleAndPermissionSeeder gives the admin role
        // every permission directly, so this hook only matters as a safety
        // net for the platform team.
        Gate::before(function ($user) {
            return $user->hasRole('super_admin') ? true : null;
        });

        /**
         * The 429 body for the public-registration limiters.
         *
         * The framework's default is a bare "Too Many Attempts." The public
         * registration form is filled in by coop members, not developers, and
         * the frontend strips that exact phrase as framework boilerplate — so
         * a throttled applicant saw a blank error and simply retried, spending
         * the little quota they had left. This copy must never contain that
         * phrase. `retry_after` is seconds, so the form can count down instead
         * of guessing.
         *
         * Signature is fixed by ThrottleRequests::buildException().
         *
         * @param  array<string, int>  $headers
         */
        $tooMany = function (Request $request, array $headers): JsonResponse {
            return response()->json([
                'message' => "You've made several submissions in a short time. Please wait a few minutes and try again.",
                'retry_after' => (int) ($headers['Retry-After'] ?? 0),
            ], 429, $headers);
        };

        // API rate limiters
        RateLimiter::for('api', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();

            // Signed file reads get their own bucket rather than a raised
            // global ceiling: a photo-heavy list render must not be able to
            // exhaust the quota that protects every other endpoint, and the
            // reverse — an export loop starving the images on screen — is
            // just as bad. Separate `by()` prefix, so the two never share a
            // counter. Deliberately NOT Limit::none(): these routes stream
            // bytes off disk and stay worth capping.
            if ($request->routeIs('files.*')) {
                return Limit::perMinute(self::FILE_READS_PER_MINUTE)->by('files:'.$key);
            }

            return Limit::perMinute(60)->by($key);
        });

        /**
         * Login attempts, two tiers.
         *
         * This was a single Limit::perMinute(10)->by($request->ip()). Every
         * browser call reaches this API through the frontend's server-side
         * proxy, and TRUSTED_PROXIES ships empty, so that one bucket was — and
         * the address tier below still is — every login on the deployment.
         * Splitting the credential out is what makes the limit meaningful
         * without waiting on a trusted proxy.
         *
         * The tight tier keys on the credential being tried — `login` is the
         * field AuthController::login reads — combined with the address, which
         * is Laravel's own ThrottlesLogins shape. Keying on the username alone
         * would let anyone lock a named admin out of their own console at
         * will, and that trade is worse than the one it prevents. This tier is
         * the one actually stopping a brute force today.
         *
         * The address tier is a coop-wide backstop while every request shares
         * one address. 40 per 5 minutes is deliberately burstier than the 10
         * per minute it replaces: thirty staff signing in together at 8am used
         * to hit a wall in the first minute, and locking a whole coop out of
         * its only console is a worse failure than the sustained rate it
         * gives up (8/min against 10/min).
         *
         * The credential is hashed because rate-limiter keys are cache keys
         * and CACHE_STORE is `database` on every box: usernames and email
         * addresses do not belong in a table that nothing treats as sensitive.
         *
         * `login` is read RAW here — LoginRequest validates it inside the
         * controller, which is downstream of this closure — so it can be any
         * JSON type the caller likes. It must be narrowed to a string rather
         * than cast: `(string) []` raises an E_WARNING, HandleExceptions turns
         * that into a thrown ErrorException *inside this closure*, and the
         * throw happens before any hit() is recorded. The attempt would go
         * uncounted and write a full stack trace to an unrotated log on every
         * request. Narrowing keeps it a 422 from LoginRequest, and a counted
         * one.
         */
        RateLimiter::for('auth', function (Request $request) {
            $login = $request->input('login');
            $login = is_string($login) ? $login : '';

            $credential = hash('sha256', Str::lower(trim($login)).'|'.$request->ip());

            return [
                Limit::perMinutes(5, 10)->by('auth:cred:'.$credential),
                Limit::perMinutes(5, 40)->by('auth:ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('exports', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });

        /**
         * Anonymous borrower creation — POST /borrowers, one request per
         * registration. The KYC uploads that follow it are metered separately
         * by `registration-uploads` below.
         *
         * This was 5 per 10 minutes covering the create AND both upload
         * endpoints, so a single applicant spent 3-5 of the 5 slots and the
         * next person to open the form was refused. Splitting the uploads out
         * is what makes a per-IP number meaningful here at all.
         *
         * Two tiers, and they MUST carry different `by()` prefixes:
         * ThrottleRequests keys each limit as md5($limiterName.$limit->key),
         * so two tiers sharing a key silently become one counter that every
         * request decrements twice.
         *
         * Burst absorbs a family or a branch office filling in forms together
         * on one NAT address; the hourly tier is what a scripted flood runs
         * into. Operators creating borrowers in bulk are exempt — they are
         * already authorised and would otherwise share quota with the public.
         */
        RateLimiter::for('public-registration', function (Request $request) use ($tooMany) {
            if ($request->user()) {
                return Limit::none();
            }

            $ip = $request->ip();

            return [
                Limit::perMinutes(10, 8)->by('reg:burst:'.$ip)->response($tooMany),
                Limit::perHour(30)->by('reg:hour:'.$ip)->response($tooMany),
            ];
        });

        /**
         * The two X-Submission-Token upload endpoints (photo + valid IDs).
         * Two tiers, and BOTH always apply.
         *
         * The token tier is the fair one. A token is issued per borrower row
         * and lives for BorrowerSubmissionTokenService::TTL_MINUTES, which
         * makes it exactly the budget we want to meter: one registration's
         * worth of uploads. It is also the one identifier here that no proxy
         * hop can collapse and no caller can forge — a submission token is
         * unguessable, and AllowAuthOrSubmissionToken has already bound it to
         * this borrower before the limiter runs. 24 is a photo plus several
         * valid IDs, each with a front and a back, plus room to retype a wrong
         * ID number or re-shoot a blurry photo. It is hashed for the same
         * reason the column is hashed: rate-limiter keys are cache keys, and a
         * live upload credential must not be readable from the cache table.
         *
         * The address tier is the one that bounds bytes-to-disk, and it is
         * unconditional because tokens are free: every POST /borrowers mints
         * another one. A per-token budget alone is not a budget at all — 30
         * tokens an hour (the `public-registration` ceiling) times 24 uploads
         * times a 20 MB valid-ID pair is ~14 GB an hour onto the private KYC
         * volume from one anonymous address, comfortably under the 60/min
         * `api` limiter. That was previously capped only by the shared-bucket
         * collapse this change removes, so the accident has to be replaced
         * with something deliberate. 120/hour lines up with the 30 creates an
         * hour the same address is already allowed (about four uploads each)
         * and puts a ~2.4 GB/hour ceiling on the worst case. Operators are
         * exempt entirely, so a branch office that needs more should be
         * signing in rather than using the public form.
         *
         * Distinct `by()` prefixes, so the two tiers cannot collapse onto one
         * counter. Token tier first: when a legitimate applicant runs out it
         * should be their own 15-minute retry-after they are told to wait, not
         * the hour-long one.
         *
         * The tokenless case keeps only the address tier. It is unreachable
         * today — AllowAuthOrSubmissionToken 401s anything with no token and
         * no bearer before this runs — but a null key would be a single global
         * bucket if the middleware order on those routes ever changed, and
         * that is the bug this whole change exists to fix.
         */
        RateLimiter::for('registration-uploads', function (Request $request) use ($tooMany) {
            if ($request->user()) {
                return Limit::none();
            }

            $limits = [];

            if ($token = $request->header('X-Submission-Token')) {
                $limits[] = Limit::perMinutes(BorrowerSubmissionTokenService::TTL_MINUTES, 24)
                    ->by('reg-upload:tok:'.hash('sha256', $token))
                    ->response($tooMany);
            }

            $limits[] = Limit::perHour(120)
                ->by('reg-upload:ip:'.$request->ip())
                ->response($tooMany);

            return $limits;
        });
    }
}
