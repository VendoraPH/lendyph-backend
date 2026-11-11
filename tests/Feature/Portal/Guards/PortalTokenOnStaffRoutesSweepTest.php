<?php

namespace Tests\Feature\Portal\Guards;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Tests\Feature\Portal\PortalTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Every authenticated staff route answers 401 to a valid member portal token
 * (portal design §8). Built from the live route list, so a staff route added
 * later is swept by default.
 */
class PortalTokenOnStaffRoutesSweepTest extends TestCase
{
    use PortalTestHelpers, SetupLendyPH;

    public function test_every_staff_route_refuses_a_portal_token(): void
    {
        $this->seedAndLogin();
        $this->enablePortal();
        $this->withoutMiddleware(ThrottleRequests::class);

        $portalUser = $this->portalMember();
        $token = $this->portalToken($portalUser);

        $swept = 0;
        $leaks = [];

        foreach ($this->staffRoutes() as $route) {
            $uri = '/'.preg_replace_callback('/\{(\w+)\??\}/', fn (array $m) => $this->valueFor($route, $m[1], (string) $portalUser->borrower_id), $route->uri());

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $status = $this->portalJson($method, $uri, [], $token)->status();
                $swept++;

                if ($status !== 401) {
                    $leaks[] = "{$method} /{$route->uri()} answered {$status}";
                }
            }
        }

        $this->assertGreaterThan(300, $swept, 'The sweep found fewer staff routes than expected; is it reading the route list?');
        $this->assertSame([], $leaks, "These staff routes accept a member portal token:\n  ".implode("\n  ", $leaks));
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $portalUser->id]);
    }

    /**
     * A value the route's own constraint accepts, so the route is matched and
     * the 401 comes from the guard, never from a 404 for an unmatched URL.
     */
    private function valueFor(Route $route, string $parameter, string $id): string
    {
        $pattern = $route->wheres[$parameter] ?? null;

        if ($pattern === null) {
            return $id;
        }

        if (preg_match('/^\(?([A-Za-z0-9_\-]+)\|/', $pattern, $first) === 1) {
            return $first[1];
        }

        foreach ([$id, (string) Str::uuid(), '2026', 'members', 'a'] as $candidate) {
            if (preg_match('#^(?:'.$pattern.')$#u', $candidate) === 1) {
                return $candidate;
            }
        }

        $this->fail("No sample value for {{$parameter}} on /{$route->uri()} (pattern {$pattern}).");
    }

    /** @return list<Route> */
    private function staffRoutes(): array
    {
        return array_values(array_filter(
            Router::getRoutes()->getRoutes(),
            fn (Route $route) => str_starts_with($route->uri(), 'api/')
                && in_array('auth:sanctum', $route->gatherMiddleware(), true),
        ));
    }
}
