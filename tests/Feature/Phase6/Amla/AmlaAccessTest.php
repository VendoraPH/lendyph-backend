<?php

namespace Tests\Feature\Phase6\Amla;

use App\Models\AmlaLogEntry;
use App\Providers\AmlaServiceProvider;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Foundation\AmlaMonitoring;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Who may reach the AMLA foundation (AMLA design §4.12). Each endpoint checks
 * its `amla:*` key; settings, change requests and the AMLA log are
 * company-wide, so they also need `branches:view_all`. A refusal is a 403 in
 * words, never a permission key, and is written to the AMLA log. The
 * monitoring switch is a server setting, off by default.
 */
class AmlaAccessTest extends TestCase
{
    use AmlaTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /**
     * Each endpoint, the keys that open it, and whether it needs head-office access.
     *
     * @return array<string, array{0: string, 1: string, 2: list<string>, 3: bool}>
     */
    public static function endpoints(): array
    {
        return [
            'status' => ['GET', '/api/amla/status', ['amla:view'], false],
            'calendar' => ['GET', '/api/amla/calendar?year=2026', ['amla:view'], false],
            'deadline preview' => ['GET', '/api/amla/deadlines/preview?kind=ctr&from=2026-12-22', ['amla:view'], false],
            'settings' => ['GET', '/api/amla/settings', ['amla:view', 'amla:settings'], true],
            'settings history' => ['GET', '/api/amla/settings/versions', ['amla:settings', 'amla:audit'], true],
            'change requests' => ['GET', '/api/amla/change-requests', ['amla:settings'], true],
            'ask for a change' => ['POST', '/api/amla/change-requests', ['amla:settings'], true],
            'log' => ['GET', '/api/amla/log', ['amla:audit'], true],
            'cdd worklist' => ['GET', '/api/amla/cdd/subjects', ['amla:cdd'], false],
            'risk rules' => ['GET', '/api/amla/risk-rules', ['amla:cdd', 'amla:settings', 'amla:audit'], true],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_each_endpoint_needs_its_key(string $method, string $uri, array $keys, bool $headOffice): void
    {
        $others = array_values(array_diff(AmlaAccess::PERMISSIONS, $keys));
        $outsider = $this->amlaUser([...$others, 'branches:view_all']);
        $holders = [];

        foreach ($keys as $key) {
            $holders[$key] = [$this->amlaUser([$key, 'branches:view_all']), $this->amlaUser([$key])];
        }

        $denied = AmlaLogEntry::query()->where('action', 'access_denied')->count();
        $before = $this->outsideAmlaCounts();

        $response = $this->actingAs($outsider)->json($method, $uri)->assertForbidden();
        $this->assertStringNotContainsString('amla:', (string) $response->json('message'));
        $this->assertSame($denied + 1, AmlaLogEntry::query()->where('action', 'access_denied')->count());

        foreach ($holders as $key => [$headOfficeHolder, $branchHolder]) {
            $status = $this->actingAs($headOfficeHolder)->json($method, $uri)->status();
            $this->assertNotSame(403, $status, "{$key} should open {$method} {$uri}");

            $branchOnly = $this->actingAs($branchHolder)->json($method, $uri)->status();
            $this->assertSame($headOffice, $branchOnly === 403, "{$method} {$uri} head-office rule");
        }

        $this->assertNothingWrittenOutsideAmla($before);
    }

    public function test_every_amla_route_is_covered_above(): void
    {
        $covered = collect(self::endpoints())->map(fn (array $row) => $row[0].' '.ltrim(strtok($row[1], '?'), '/'))->all();
        $covered = [...$covered,
            'GET api/amla/change-requests/{id}',
            'POST api/amla/change-requests/{id}/approve',
            'POST api/amla/change-requests/{id}/reject',
            'POST api/amla/change-requests/{id}/withdraw',
            'GET api/amla/cdd/{subjectType}/{id}',
            'PUT api/amla/cdd/{subjectType}/{id}',
            'POST api/amla/cdd/{subjectType}/{id}/id-verifications',
            'POST api/amla/cdd/{subjectType}/{id}/risk-assessments',
            'POST api/amla/cdd/{subjectType}/{id}/reviews',
        ];

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/amla'))
            ->flatMap(fn (RoutingRoute $route) => array_map(fn ($m) => $m.' '.$route->uri(), array_diff($route->methods(), ['HEAD'])))
            ->sort()->values()->all();

        $this->assertSame(collect($covered)->sort()->values()->all(), $routes);
    }

    public function test_a_change_request_needs_the_settings_key_and_head_office_access(): void
    {
        $id = $this->actingAs($this->settingsHolder())->postJson('/api/amla/change-requests', [
            'type' => 'settings', 'reason' => 'One.', 'values' => ['str_deadline_working_days' => 2],
        ])->assertCreated()->json('data.id');

        foreach (['GET' => '', 'POST' => '/approve'] as $method => $suffix) {
            $this->actingAs($this->amlaUser(['amla:view', 'amla:approve', 'branches:view_all']))->json($method, "/api/amla/change-requests/{$id}{$suffix}")->assertForbidden();
            $this->actingAs($this->amlaUser(['amla:settings']))->json($method, "/api/amla/change-requests/{$id}{$suffix}")->assertForbidden();
        }

        $this->actingAs($this->settingsHolder())->getJson("/api/amla/change-requests/{$id}")->assertOk()
            ->assertJsonPath('data.type_words', 'AMLA settings')
            ->assertJsonPath('data.status_words', 'Waiting for approval');
        $this->actingAs($this->settingsHolder())->getJson('/api/amla/change-requests/999999')->assertNotFound();
    }

    public function test_reject_and_withdraw_need_the_settings_key_and_head_office_access(): void
    {
        $maker = $this->settingsHolder();
        $id = $this->actingAs($maker)->postJson('/api/amla/change-requests', [
            'type' => 'settings', 'reason' => 'One.', 'values' => ['str_deadline_working_days' => 2],
        ])->assertCreated()->json('data.id');

        foreach (['reject' => ['note' => 'No.'], 'withdraw' => []] as $action => $body) {
            $this->actingAs($this->amlaUser(['amla:view', 'amla:approve', 'amla:audit', 'branches:view_all']))
                ->postJson("/api/amla/change-requests/{$id}/{$action}", $body)->assertForbidden();
            $this->actingAs($this->amlaUser(['amla:settings']))
                ->postJson("/api/amla/change-requests/{$id}/{$action}", $body)->assertForbidden()
                ->assertJsonPath('message', AmlaAccess::VIEW_ALL_REFUSAL);
        }

        $this->actingAs($maker)->postJson("/api/amla/change-requests/{$id}/reject", ['note' => 'Mine.'])->assertForbidden()
            ->assertJsonPath('message', 'You asked for this change, so withdraw it instead of rejecting it.');
        $this->actingAs($this->settingsHolder())->postJson("/api/amla/change-requests/{$id}/withdraw")->assertForbidden()
            ->assertJsonPath('message', 'Only the person who asked for this change can withdraw it.');
        $this->actingAs($maker)->postJson("/api/amla/change-requests/{$id}/withdraw")->assertOk();
    }

    public function test_a_caller_without_access_learns_nothing_about_which_requests_exist(): void
    {
        $id = $this->actingAs($this->settingsHolder())->postJson('/api/amla/change-requests', [
            'type' => 'settings', 'reason' => 'One.', 'values' => ['str_deadline_working_days' => 2],
        ])->assertCreated()->json('data.id');
        $outsider = $this->amlaUser(['amla:view']);

        foreach ([$id, 999999] as $probe) {
            $this->actingAs($outsider)->getJson("/api/amla/change-requests/{$probe}")->assertForbidden();

            foreach (['approve' => [], 'reject' => ['note' => 'x'], 'withdraw' => []] as $action => $body) {
                $this->actingAs($outsider)->postJson("/api/amla/change-requests/{$probe}/{$action}", $body)->assertForbidden();
            }
        }
    }

    public function test_change_request_ids_are_bounded_so_an_oversized_id_never_reaches_the_controller(): void
    {
        $holder = $this->settingsHolder();

        $this->actingAs($holder)->getJson('/api/amla/change-requests/123456789012345678')->assertNotFound();

        foreach (['', '/approve', '/reject', '/withdraw'] as $suffix) {
            $method = $suffix === '' ? 'GET' : 'POST';
            $this->actingAs($holder)->json($method, '/api/amla/change-requests/12345678901234567890'.$suffix, ['note' => 'x'])->assertNotFound();
            $this->actingAs($holder)->json($method, '/api/amla/change-requests/1234567890123456789'.$suffix, ['note' => 'x'])->assertNotFound();
        }

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/amla') && str_contains($route->uri(), '{id}')) {
                $this->assertSame('[0-9]{1,18}', $route->wheres['id'] ?? null, $route->uri());
            }
        }
    }

    public function test_every_amla_route_is_named_in_the_amla_family_and_throttled(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/amla'));

        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $this->assertStringStartsWith('amla.', (string) $route->getName(), $route->uri());
            $this->assertContains('throttle:amla', $route->gatherMiddleware(), $route->uri());
        }

        $stray = collect(Route::getRoutes()->getRoutesByName())
            ->filter(fn (RoutingRoute $route, string $name) => str_starts_with($name, 'amla.') && ! str_starts_with($route->uri(), 'api/amla'))
            ->keys()->all();
        $this->assertSame([], $stray);
    }

    public function test_a_caller_holding_no_amla_key_gets_ten_refusals_a_minute_and_ten_log_rows(): void
    {
        $outsider = $this->amlaUser(['loans:view']);
        $before = AmlaLogEntry::query()->where('action', 'access_denied')->count();

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($outsider)->getJson('/api/amla/status')->assertForbidden();
        }

        $this->actingAs($outsider)->getJson('/api/amla/status')->assertTooManyRequests();
        $this->assertSame($before + 10, AmlaLogEntry::query()->where('action', 'access_denied')->count());
    }

    public function test_a_caller_holding_an_amla_key_gets_a_bucket_of_their_own_of_30_a_minute(): void
    {
        $viewer = $this->amlaUser(['amla:view']);
        $other = $this->amlaUser(['amla:view']);

        for ($i = 0; $i < AmlaServiceProvider::HOLDER_PER_MINUTE; $i++) {
            $this->actingAs($viewer)->getJson('/api/amla/status')->assertOk();
        }

        $this->actingAs($viewer)->getJson('/api/amla/status')->assertTooManyRequests();
        $this->actingAs($other)->getJson('/api/amla/status')->assertOk();

        $request = Request::create('/api/amla/status', 'GET');
        $request->setUserResolver(fn () => $viewer);
        $limit = RateLimiter::limiter('amla')($request);
        $this->assertSame(30, $limit->maxAttempts);
        $this->assertSame('amla:user:User:'.$viewer->id, $limit->key);

        $request->setUserResolver(fn () => $this->amlaUser(['loans:view']));
        $limit = RateLimiter::limiter('amla')($request);
        $this->assertSame(10, $limit->maxAttempts);
        $this->assertStringStartsWith('amla:denied:', $limit->key);
    }

    public function test_monitoring_is_off_by_default_and_the_status_says_so(): void
    {
        $this->assertFalse(config('amla.monitoring_enabled'));
        $this->assertFalse(app(AmlaMonitoring::class)->enabled());

        $this->actingAs($this->amlaUser(['amla:view']))->getJson('/api/amla/status')->assertOk()
            ->assertJsonPath('data.monitoring_enabled', false)
            ->assertJsonPath('data.settings_version', 1)
            ->assertJsonPath('data.settings_to_verify', 7);

        config(['amla.monitoring_enabled' => true]);
        $this->assertTrue(app(AmlaMonitoring::class)->enabled());
    }

    public function test_the_switch_reads_the_environment_as_a_boolean(): void
    {
        $config = file_get_contents(config_path('amla.php'));

        $this->assertStringContainsString("filter_var(env('AMLA_MONITORING_ENABLED', false), FILTER_VALIDATE_BOOL)", $config);
    }
}
