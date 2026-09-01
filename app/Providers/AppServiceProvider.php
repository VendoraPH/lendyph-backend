<?php

namespace App\Providers;

use App\Models\CsvImportRun;
use App\Services\BorrowerSubmissionTokenService;
use App\Services\CsvImport\CsvImportUploadService;
use App\Services\CsvImport\ImportErrorDigest;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Throwable;

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

    /**
     * Chunk uploads allowed per operator per minute.
     *
     * A CSV migration is one long request, cut up. The largest export this API
     * accepts is 100 MiB per file in 512 KiB chunks — two hundred PUTs for one
     * file, four hundred for a run — and the client sends several concurrently
     * while polling the run for progress. Against the shared 60/min `api`
     * budget an upload throttles itself to a crawl within the first ten
     * seconds and every other screen the same admin has open starts returning
     * 429 alongside it.
     *
     * 300/min lets a run's chunks flow at roughly the rate a good connection
     * can push them while still bounding bytes-to-disk: 300 x 512 KiB is about
     * 150 MB a minute from one operator, and only an operator — every import
     * route requires `imports:process`, which only super_admin and admin hold.
     */
    private const IMPORT_CHUNKS_PER_MINUTE = 300;

    /**
     * Everything else under /imports: opening a run, assembling, and the
     * status polling a client does while it uploads.
     *
     * Kept separate from the chunk tier so a client polling every second
     * cannot eat the budget its own upload needs, and so a runaway poll is
     * visible as a poll rather than as a stalled upload.
     */
    private const IMPORT_CONTROL_CALLS_PER_MINUTE = 120;

    /**
     * The `api` limiter's ceiling for import routes.
     *
     * Deliberately above IMPORT_CHUNKS_PER_MINUTE + IMPORT_CONTROL_CALLS_PER_MINUTE
     * so the two tiers above are what actually bind, and this is only the
     * backstop that keeps an import route somebody forgets to attach
     * `throttle:imports` to from running against no ceiling at all.
     */
    private const IMPORT_REQUESTS_PER_MINUTE = 480;

    /**
     * What a caller WITHOUT `imports:process` gets on an import route.
     *
     * The elevated tiers above are sized for a legitimate migration and are
     * eight times the app-wide ceiling. Handing them out on the strength of the
     * route name alone would give every authenticated user — viewer, collector,
     * cashier — 300 requests a minute whose bodies nginx buffers and PHP writes
     * to disk before the controller's 403 is ever reached. The permission check
     * is what makes the elevated tier a privilege rather than a property of the
     * URL.
     */
    private const IMPORT_DENIED_PER_MINUTE = 10;

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

        /**
         * Retention for CSV migration uploads.
         *
         * The moment a run is DECIDED — completed, or cancelled by an operator
         * — the files it uploaded stop being useful and start being a
         * liability: an assembled customers CSV is every member's name,
         * birthdate, contact number and income in plaintext, and once those
         * rows are staged into `csv_import_rows` the file has done its job.
         * Hung off the model rather than written into each transition so that
         * whoever moves a run to a terminal phase — the importer, a future
         * admin action, anything — cannot forget to clean up. Cancellation
         * calls the same method directly, so it never depends on this listener
         * being wired.
         *
         * DELIBERATELY NARROWER THAN `CLOSED_PHASES`. This keyed off that list,
         * which includes `failed`, and the processor writes a run off as
         * `failed` on ANY Throwable — so a single deadlock or lock-wait timeout
         * deleted both assembled source files the instant it was caught,
         * leaving a half-imported book with nothing to re-run from. Verified
         * from a console process, not theorised: the file existed before the
         * save and was gone after it. `failed` runs now keep their files and
         * are collected by the reconciling sweep once
         * `imports.failed_run_retention_hours` has passed. The staged rows are
         * untouched either way, so nothing is lost by waiting.
         *
         * See CsvImportUploadService::STORAGE_RELEASE_PHASES — the list is
         * published there rather than written out here, so this listener cannot
         * drift from the sweep that backs it up.
         *
         * `wasChanged('phase')` so this fires on the transition rather than on
         * every subsequent save of an already-finished run.
         *
         * The one gap worth knowing: Eloquent model events do not fire for mass
         * updates. `CsvImportRun::query()->update(['phase' => 'completed'])`
         * will NOT trigger this; `$run->update(...)` or `save()` will. It is
         * covered from the other side by
         * CsvImportUploadService::releaseAbandonedStorage(), a reconciling
         * sweep that walks from the run row to what is actually on disk. This
         * listener is the timely path; that sweep is the guaranteed one.
         *
         * (The importer's 14-day prune is NOT an example of that gap — it saves
         * per row, so this listener does fire for it. What matters about the
         * prune is the other thing: it saves inside a transaction, which is why
         * releaseStorage() defers its unlinks to `DB::afterCommit` rather than
         * unlinking where it is called.)
         *
         * Safe to reach here from a console or queue process as well as a web
         * request. The uid trap on this disk is about CREATING files a
         * root-owned 0700 directory then hides from php-fpm; deleting creates
         * nothing and root may unlink anything.
         *
         * `csv_import_rows` still holds the same personal data row by row and
         * needs its own retention decision, which is a policy call rather than
         * something this listener can make.
         *
         * HOUSEKEEPING MUST NOT BE ABLE TO FAIL THE IMPORT IT IS TIDYING UP
         * AFTER. A `saved` listener runs inside the save, so anything thrown
         * here propagates out of `$run->save()` and out of whatever was calling
         * it. The processor completes a run by saving it into `completed`
         * inside its own try/catch; an unlink that failed on a disk error, a
         * permissions change or an NFS blip would therefore turn a run that had
         * already written every member and every loan correctly into `failed`.
         * The data is committed and only the bookkeeping is wrong, which is the
         * worst available shape — the operator sees "failed" and may reasonably
         * re-run.
         *
         * So it is caught, logged at `error` with the run and the reason, and
         * the save proceeds. The trade is deliberate and only goes one way: a
         * file left on the volume is recoverable, and
         * releaseAbandonedStorage() is precisely the thing that will find it on
         * the next import or the next scheduled tick. A wrongly-failed run is
         * not recoverable without somebody editing a phase by hand.
         */
        CsvImportRun::saved(function (CsvImportRun $run): void {
            if (! $run->wasChanged('phase')) {
                return;
            }

            if (! in_array($run->phase, CsvImportUploadService::STORAGE_RELEASE_PHASES, true)) {
                return;
            }

            try {
                app(CsvImportUploadService::class)->releaseStorage($run);
            } catch (Throwable $e) {
                /*
                 * The digest, not the message. This listener fires on a phase
                 * write to a run whose staged rows are the cooperative's
                 * membership, and it logs to the DEFAULT channel — `single`:
                 * one file, never rotated, mode 644. A QueryException reaching
                 * here would carry the failing SQL with its bindings
                 * substituted. See ImportErrorDigest.
                 *
                 * This site is outside the reach of
                 * tests/Unit/CsvImportExceptionMessageArchTest.php on purpose —
                 * it is a shared provider, and an arch test that fails somebody
                 * for an unrelated line in another feature gets deleted. What
                 * holds it instead is
                 * CsvImportUploadApiTest::test_a_failing_storage_release_does_not_fail_the_run_it_was_tidying_up_after(),
                 * which pins the shape of this very context.
                 */
                Log::error('csv-import: could not release a finished run\'s storage', [
                    'csv_import_run_id' => $run->id,
                    'phase' => $run->phase,
                    'consequence' => 'The run\'s uploaded CSVs are still on the private disk. They hold member '
                        .'names, birthdates, contact numbers and incomes. releaseAbandonedStorage() will '
                        .'reconcile them on the next import or scheduled tick; if it keeps failing, that is a '
                        .'disk or permissions fault to fix rather than a run to re-import.',
                ] + ImportErrorDigest::context($e));

                ImportErrorDigest::recordDiagnostics($e, [
                    'csv_import_run_id' => $run->id,
                    'phase' => $run->phase,
                    'stage' => 'storage-release',
                ]);
            }
        });

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

            /**
             * Import routes are metered by the `imports` limiter below; this
             * branch exists because that limiter alone would be inert.
             *
             * `ThrottleRequests::class.':api'` is PREPENDED to the whole api
             * middleware group in bootstrap/app.php, so it applies to every
             * route in routes/api.php whether or not the route also names a
             * limiter of its own. A route-level `throttle:imports` therefore
             * stacks on top of this one rather than replacing it, and the
             * lower of the two is what a caller feels — 60/min, which is a
             * fifth of what one upload needs. Raising the ceiling has to
             * happen here, exactly as it does for `files.*` above.
             *
             * Its own `by()` prefix, so it cannot share a counter with either
             * tier of the `imports` limiter.
             */
            if ($request->routeIs('imports.*')) {
                if (! $request->user()?->can('imports:process')) {
                    return Limit::perMinute(self::IMPORT_DENIED_PER_MINUTE)->by('imports:api:denied:'.$key);
                }

                return Limit::perMinute(self::IMPORT_REQUESTS_PER_MINUTE)->by('imports:api:'.$key);
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

        /**
         * CSV migration import — the chunked upload and everything around it.
         *
         * Keyed on the authenticated user id, never the address. Every route
         * under /imports sits inside the `auth:sanctum` group, and
         * `Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests`
         * precedes `ThrottleRequests` in the framework's middleware priority
         * list, so the user is already resolved by the time this closure runs.
         * Keying on the address would be actively wrong here: TRUSTED_PROXIES
         * ships empty and every browser call arrives through the frontend's
         * server-side rewrite, so `$request->ip()` is one value for the whole
         * deployment and one admin's import would throttle another's.
         *
         * One Limit is returned, not two — the tier is chosen by route rather
         * than layered. Two tiers here would have to carry different `by()`
         * prefixes or silently collapse into a single counter that every
         * request decrements twice, the trap documented on
         * `public-registration` above; picking one avoids the question
         * entirely, and the two budgets are meant to be independent anyway.
         *
         * The IP fallback is unreachable while these routes stay behind
         * `auth:sanctum`, and exists so that a future unauthenticated import
         * route cannot key on null and hand every caller one shared bucket.
         */
        RateLimiter::for('imports', function (Request $request) {
            $user = $request->user();
            $key = $user?->id ?: $request->ip();

            /**
             * The elevated tiers are for people who can actually run an import.
             * ThrottleRequests sorts ahead of SubstituteBindings and long ahead
             * of the controller's authorize(), so without this check a caller
             * who will certainly be refused still gets the migration-sized
             * budget — and spends it writing chunk bodies to disk.
             */
            if (! $user?->can('imports:process')) {
                return Limit::perMinute(self::IMPORT_DENIED_PER_MINUTE)->by('imports:denied:'.$key);
            }

            if ($request->routeIs('imports.chunk')) {
                return Limit::perMinute(self::IMPORT_CHUNKS_PER_MINUTE)->by('imports:chunk:'.$key);
            }

            return Limit::perMinute(self::IMPORT_CONTROL_CALLS_PER_MINUTE)->by('imports:ctl:'.$key);
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
