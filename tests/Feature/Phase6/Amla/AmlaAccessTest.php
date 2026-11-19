<?php

namespace Tests\Feature\Phase6\Amla;

use App\Models\AmlaLogEntry;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Foundation\AmlaMonitoring;
use Illuminate\Routing\Route as RoutingRoute;
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
        ];
    }

    #[DataProvider('endpoints')]
    public function test_each_endpoint_needs_its_key(string $method, string $uri, array $keys, bool $headOffice): void
    {
        $others = array_values(array_diff(AmlaAccess::PERMISSIONS, $keys));
        $denied = AmlaLogEntry::query()->where('action', 'access_denied')->count();

        $response = $this->actingAs($this->amlaUser([...$others, 'branches:view_all']))->json($method, $uri)->assertForbidden();
        $this->assertStringNotContainsString('amla:', (string) $response->json('message'));
        $this->assertSame($denied + 1, AmlaLogEntry::query()->where('action', 'access_denied')->count());

        foreach ($keys as $key) {
            $status = $this->actingAs($this->amlaUser([$key, 'branches:view_all']))->json($method, $uri)->status();
            $this->assertNotSame(403, $status, "{$key} should open {$method} {$uri}");

            $branchOnly = $this->actingAs($this->amlaUser([$key]))->json($method, $uri)->status();
            $this->assertSame($headOffice, $branchOnly === 403, "{$method} {$uri} head-office rule");
        }
    }

    public function test_every_amla_route_is_covered_above(): void
    {
        $covered = collect(self::endpoints())->map(fn (array $row) => $row[0].' '.ltrim(strtok($row[1], '?'), '/'))->all();
        $covered = [...$covered,
            'GET api/amla/change-requests/{changeRequest}',
            'POST api/amla/change-requests/{changeRequest}/approve',
            'POST api/amla/change-requests/{changeRequest}/reject',
            'POST api/amla/change-requests/{changeRequest}/withdraw',
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
