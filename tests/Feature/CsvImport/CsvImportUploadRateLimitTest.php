<?php

namespace Tests\Feature\CsvImport;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The metering around a CSV migration upload.
 *
 * A run is one logical transfer cut into hundreds of requests, so the shared
 * 60/min `api` budget is not merely tight here, it is a hard stop a third of
 * the way through a legitimate import — and it takes every other screen the
 * same admin has open down with it. These tests pin the two things that keep
 * that from happening: import routes are metered on their own counters, and
 * those counters are per-operator rather than per-address.
 */
class CsvImportUploadRateLimitTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        Storage::fake('private');
        config(['imports.chunk_size' => 65536]);
    }

    private function importer(): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole(Role::where('name', 'admin')->firstOrFail());

        return $user;
    }

    private function requestFor(string $routeName, ?User $user = null): Request
    {
        $request = Request::create('/api/imports', 'POST');

        $route = new RoutingRoute(['POST'], 'api/imports', []);
        $route->name($routeName);

        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    public function test_chunk_uploads_and_control_calls_are_metered_separately(): void
    {
        $limiter = RateLimiter::limiter('imports');
        $user = $this->importer();

        $chunkLimit = $limiter($this->requestFor('imports.chunk', $user));
        $controlLimit = $limiter($this->requestFor('imports.store', $user));

        $this->assertSame(300, $chunkLimit->maxAttempts);
        $this->assertSame(120, $controlLimit->maxAttempts);

        /**
         * ThrottleRequests keys each limit as md5($limiterName.$limit->key), so
         * two tiers that share a `by()` key silently become ONE counter that
         * every request decrements twice. Distinct prefixes are what keep a
         * client polling the run from spending the budget its own upload needs.
         */
        $this->assertNotSame($chunkLimit->key, $controlLimit->key);
        $this->assertStringStartsWith('imports:chunk:', $chunkLimit->key);
        $this->assertStringStartsWith('imports:ctl:', $controlLimit->key);
    }

    public function test_the_import_counters_are_per_operator_not_per_address(): void
    {
        $one = $this->importer();
        $two = $this->importer();

        $limiter = RateLimiter::limiter('imports');

        /**
         * TRUSTED_PROXIES ships empty and every browser call reaches this API
         * through the frontend's server-side rewrite, so `$request->ip()` is a
         * single value for an entire deployment. Keying on it would mean one
         * admin's import throttled another's — and, on a coop with one office,
         * throttled everybody's.
         */
        $this->assertNotSame(
            $limiter($this->requestFor('imports.chunk', $one))->key,
            $limiter($this->requestFor('imports.chunk', $two))->key,
        );

        $this->assertStringEndsWith((string) $one->id, $limiter($this->requestFor('imports.chunk', $one))->key);
    }

    public function test_the_shared_api_limiter_raises_its_ceiling_for_import_routes(): void
    {
        $limiter = RateLimiter::limiter('api');
        $user = $this->importer();

        $importLimit = $limiter($this->requestFor('imports.chunk', $user));
        $ordinaryLimit = $limiter($this->requestFor('borrowers.index', $user));

        // ThrottleRequests:api is PREPENDED to the whole api middleware group,
        // so it applies to import routes whether or not they name a limiter of
        // their own. Without this branch the named `imports` limiter would be
        // inert and 60/min would still be what a client feels.
        $this->assertSame(60, $ordinaryLimit->maxAttempts);
        $this->assertSame(480, $importLimit->maxAttempts);
        $this->assertStringStartsWith('imports:api:', $importLimit->key);
        $this->assertNotSame($ordinaryLimit->key, $importLimit->key);
    }

    public function test_a_caller_without_the_permission_gets_no_migration_sized_budget(): void
    {
        $viewer = User::factory()->create(['branch_id' => $this->branch->id]);
        $viewer->assignRole(Role::where('name', 'viewer')->firstOrFail());

        /**
         * The elevated tiers are a privilege, not a property of the URL. A
         * viewer who will certainly be refused by the controller must not first
         * be granted 300 requests a minute whose bodies nginx buffers and PHP
         * writes to disk on the way to that 403.
         */
        $importsLimit = RateLimiter::limiter('imports')($this->requestFor('imports.chunk', $viewer));
        $apiLimit = RateLimiter::limiter('api')($this->requestFor('imports.chunk', $viewer));

        $this->assertSame(10, $importsLimit->maxAttempts);
        $this->assertSame(10, $apiLimit->maxAttempts);
        $this->assertStringStartsWith('imports:denied:', $importsLimit->key);
        $this->assertStringStartsWith('imports:api:denied:', $apiLimit->key);
    }

    public function test_an_upload_longer_than_the_shared_sixty_a_minute_budget_is_not_throttled(): void
    {
        $admin = $this->importer();
        $this->actingAs($admin);

        $customers = str_repeat('x', 65536 * 2);

        $runId = $this->postJson('/api/imports', [
            'branch_id' => $this->branch->id,
            'files' => [
                'customers' => ['filename' => 'm.csv', 'size_bytes' => strlen($customers), 'sha256' => hash('sha256', $customers)],
                'loans' => ['filename' => 'l.csv', 'size_bytes' => 1, 'sha256' => hash('sha256', 'x')],
            ],
        ])->assertCreated()->json('run.id');

        /**
         * Sixty-five calls, well past the shared budget. Their bodies are
         * deliberately junk — a throttle counts a request before anything looks
         * at it, so a 422 exercises the limiter exactly as a real chunk does,
         * without pushing four megabytes through the test client.
         */
        for ($i = 0; $i < 65; $i++) {
            $response = $this->post("/api/imports/{$runId}/files/customers/chunks/0", [
                'sha256' => hash('sha256', 'nope'),
                'chunk' => UploadedFile::fake()->createWithContent('chunk.part', 'too short'),
            ], ['Accept' => 'application/json']);

            $this->assertNotSame(429, $response->status(), "Request #{$i} was rate limited; a real upload is hundreds of requests long.");
        }
    }
}
