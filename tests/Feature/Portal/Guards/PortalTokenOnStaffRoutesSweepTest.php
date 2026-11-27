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
 * A valid member portal token gets exactly what a guest gets on every API
 * route outside the portal (portal design §8; review L8): 401 on every staff
 * route, and the public answer on the public ones. Built from the live route
 * list, so a route added later is swept by default.
 */
class PortalTokenOnStaffRoutesSweepTest extends TestCase
{
    use PortalTestHelpers, SetupLendyPH;

    public function test_a_portal_token_gets_exactly_what_a_guest_gets_on_every_non_portal_route(): void
    {
        $this->seedAndLogin();
        $this->enablePortal();
        $this->withoutMiddleware(ThrottleRequests::class);

        $portalUser = $this->portalMember();
        $token = $this->portalToken($portalUser);

        $swept = 0;
        $authenticated = 0;
        $leaks = [];

        foreach ($this->nonPortalRoutes() as $route) {
            $uri = '/'.preg_replace_callback('/\{(\w+)\??\}/', fn (array $m) => $this->valueFor($route, $m[1], (string) $portalUser->borrower_id), $route->uri());
            $staffOnly = in_array('auth:sanctum', $route->gatherMiddleware(), true);

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $guest = $this->portalJson($method, $uri)->status();
                $withToken = $this->portalJson($method, $uri, [], $token)->status();
                $swept++;

                if ($staffOnly) {
                    $authenticated++;
                }

                if ($withToken !== $guest || ($staffOnly && $withToken !== 401)) {
                    $leaks[] = "{$method} /{$route->uri()} answered {$withToken} to a portal token and {$guest} to a guest";
                }
            }
        }

        $this->assertGreaterThan(300, $authenticated, 'The sweep found fewer staff routes than expected; is it reading the route list?');
        $this->assertGreaterThan($authenticated, $swept, 'The sweep covers the public routes too.');
        $this->assertSame([], $leaks, "A portal token is treated differently from a guest on:\n  ".implode("\n  ", $leaks));
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

    /** Every API route outside the member portal, staff and public alike. @return list<Route> */
    private function nonPortalRoutes(): array
    {
        return array_values(array_filter(
            Router::getRoutes()->getRoutes(),
            fn (Route $route) => str_starts_with($route->uri(), 'api/') && ! str_starts_with($route->uri(), 'api/portal/'),
        ));
    }
}
