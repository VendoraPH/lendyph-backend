<?php

namespace Tests\Feature\Portal\Guards;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\Feature\Portal\PortalTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Every signed-in portal route answers 401 to a super_admin's staff token and
 * to a signed-in staff session (portal design §8). Built from the live route
 * list, so a portal route added later is swept by default.
 */
class StaffTokenOnPortalRoutesSweepTest extends TestCase
{
    use PortalTestHelpers, SetupLendyPH;

    public function test_every_signed_in_portal_route_refuses_a_staff_token(): void
    {
        $this->seedAndLogin();
        $this->enablePortal();
        $this->withoutMiddleware(ThrottleRequests::class);
        $token = $this->staffToken($this->admin);

        $leaks = $this->sweep(fn (string $method, string $uri) => $this->portalJson($method, $uri, [], $token)->status());

        $this->assertSame([], $leaks, "These portal routes accept a staff token:\n  ".implode("\n  ", $leaks));
    }

    public function test_every_signed_in_portal_route_refuses_a_staff_session(): void
    {
        $this->seedAndLogin();
        $this->enablePortal();
        $this->withoutMiddleware(ThrottleRequests::class);

        $leaks = $this->sweep(function (string $method, string $uri) {
            $this->app['auth']->forgetGuards();
            $this->actingAs($this->admin, 'web');

            return $this->json($method, $uri, [])->status();
        });

        $this->assertSame([], $leaks, "These portal routes accept a staff session:\n  ".implode("\n  ", $leaks));
    }

    /**
     * @param  callable(string, string): int  $call
     * @return list<string>
     */
    private function sweep(callable $call): array
    {
        $swept = 0;
        $leaks = [];

        foreach (Router::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            if (! str_starts_with($route->uri(), 'api/portal/') || ! in_array('auth:portal', $route->gatherMiddleware(), true)) {
                continue;
            }

            $uri = '/'.preg_replace('/\{\w+\??\}/', '1', $route->uri());

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $status = $call($method, $uri);
                $swept++;

                if ($status !== 401) {
                    $leaks[] = "{$method} /{$route->uri()} answered {$status}";
                }
            }
        }

        $this->assertGreaterThanOrEqual(9, $swept, 'The sweep found fewer signed-in portal routes than expected.');

        return $leaks;
    }
}
