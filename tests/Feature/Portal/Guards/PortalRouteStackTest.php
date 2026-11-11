<?php

namespace Tests\Feature\Portal\Guards;

use App\Http\Middleware\Portal\CheckPortalTokenExpiry;
use App\Http\Middleware\Portal\EnsurePortalEnabled;
use App\Http\Middleware\Portal\EnsurePortalMemberActive;
use App\Http\Middleware\Portal\EnsurePortalToken;
use App\Http\Middleware\Portal\PortalNoStore;
use App\Http\Middleware\Portal\PortalStack;
use App\Http\Middleware\Portal\RequirePortalTerms;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\Feature\Portal\PortalTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Every `api/portal/*` route carries the portal stack (portal design §5, §8),
 * read off the stack the framework actually builds (gatherRouteMiddleware,
 * after SortedMiddleware), like UserRouteEnumerationTest does for staff.
 */
class PortalRouteStackTest extends TestCase
{
    use PortalTestHelpers, SetupLendyPH;

    /** Public routes, by name. Everything else under api/portal/ is signed in. */
    private const PUBLIC_ROUTES = [
        'portal.config', 'portal.terms', 'portal.auth.login',
        'portal.auth.codes.verify', 'portal.auth.codes.otp', 'portal.auth.codes.complete',
    ];

    /** @return list<Route> */
    private function portalRoutes(): array
    {
        return array_values(array_filter(
            Router::getRoutes()->getRoutes(),
            fn (Route $route) => str_starts_with($route->uri(), 'api/portal/'),
        ));
    }

    /** @return list<string> */
    private function stack(Route $route): array
    {
        return array_values(array_filter(app('router')->gatherRouteMiddleware($route), 'is_string'));
    }

    public function test_every_signed_in_portal_route_carries_the_portal_stack_ahead_of_binding(): void
    {
        $gates = [EnsurePortalToken::class, CheckPortalTokenExpiry::class, EnsurePortalMemberActive::class, RequirePortalTerms::class];
        $checked = 0;

        foreach ($this->portalRoutes() as $route) {
            if (in_array($route->getName(), self::PUBLIC_ROUTES, true)) {
                continue;
            }

            $label = implode('|', $route->methods()).' /'.$route->uri();
            $declared = $route->gatherMiddleware();
            $stack = $this->stack($route);

            foreach (PortalStack::signedIn() as $middleware) {
                $this->assertContains($middleware, $declared, "{$label} lacks {$middleware}.");
            }

            $this->assertNotContains('auth:sanctum', $declared, "{$label} takes a staff token.");
            $this->assertStringStartsWith('portal.', (string) $route->getName(), "{$label} needs a portal.<group>.<action> name.");

            $auth = $this->indexOf($stack, fn (string $m) => str_starts_with($m, Authenticate::class));
            $binding = $this->indexOf($stack, fn (string $m) => $m === SubstituteBindings::class);
            $this->assertNotNull($auth, "{$label}: no Authenticate in the stack.");

            $last = $auth;
            foreach ($gates as $gate) {
                $at = $this->indexOf($stack, fn (string $m) => $m === $gate);
                $this->assertNotNull($at, "{$label}: {$gate} is not in the gathered stack.");
                $this->assertGreaterThan($last, $at, "{$label}: {$gate} runs out of order.");
                $this->assertLessThan($binding, $at, "{$label}: {$gate} runs after route-model binding.");
                $last = $at;
            }

            $checked++;
        }

        $this->assertGreaterThanOrEqual(9, $checked);
    }

    public function test_every_public_portal_route_is_no_store_and_rate_limited(): void
    {
        $names = [];

        foreach ($this->portalRoutes() as $route) {
            if (! in_array($route->getName(), self::PUBLIC_ROUTES, true)) {
                continue;
            }

            $names[] = $route->getName();
            $declared = $route->gatherMiddleware();
            $label = '/'.$route->uri();

            $this->assertContains(PortalNoStore::class, $declared, "{$label} lacks no-store.");
            $this->assertNotEmpty(array_filter($declared, fn ($m) => is_string($m) && str_starts_with($m, 'throttle:portal-')), "{$label} has no portal limiter.");
            $this->assertNotContains('auth:sanctum', $declared);

            // Config answers while the portal is off; sign-in checks the switch
            // itself so the refused attempt is recorded.
            if (! in_array($route->getName(), ['portal.config', 'portal.auth.login'], true)) {
                $this->assertContains(EnsurePortalEnabled::class, $declared, "{$label} lacks the portal switch.");
            }
        }

        sort($names);
        $expected = self::PUBLIC_ROUTES;
        sort($expected);
        $this->assertSame($expected, $names);
    }

    public function test_no_portal_route_names_a_member_or_a_bound_model(): void
    {
        foreach ($this->portalRoutes() as $route) {
            $this->assertSame([], array_diff($route->parameterNames(), ['id']), '/'.$route->uri().' takes a parameter other than {id}.');
            $this->assertArrayNotHasKey('id', $route->bindingFields(), '/'.$route->uri().' binds {id} to a model.');
        }
    }

    /**
     * Sending a member id changes nothing: the member comes from the token.
     */
    public function test_a_member_id_in_the_query_or_body_changes_nothing(): void
    {
        $this->seedAndLogin();
        $this->enablePortal();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->freezeTime();

        $me = $this->portalMember();
        $other = $this->portalMember();
        $token = $this->portalToken($me);
        $foreign = ['borrower_id' => $other->borrower_id, 'member_id' => $other->borrower_id, 'portal_user_id' => $other->id];
        $checked = 0;

        foreach ($this->portalRoutes() as $route) {
            if (in_array($route->getName(), self::PUBLIC_ROUTES, true) || ! in_array('GET', $route->methods(), true) || $route->parameterNames() !== []) {
                continue;
            }

            $plain = $this->portalJson('GET', '/'.$route->uri(), [], $token);
            $withForeign = $this->portalJson('GET', '/'.$route->uri().'?'.http_build_query($foreign), [], $token);

            $this->assertSame($plain->status(), $withForeign->status(), '/'.$route->uri());
            $this->assertSame($plain->json(), $withForeign->json(), '/'.$route->uri().' answered differently with another member\'s id.');
            $checked++;
        }

        $this->assertGreaterThanOrEqual(3, $checked);
    }

    /**
     * @param  list<string>  $stack
     * @param  callable(string): bool  $match
     */
    private function indexOf(array $stack, callable $match): ?int
    {
        foreach ($stack as $i => $middleware) {
            if ($match($middleware)) {
                return $i;
            }
        }

        return null;
    }

    /** Review L3 and L4: which routes carry the write limit, and that no per-member limiter precedes the permission check. */
    public function test_member_security_writes_carry_the_write_limit_and_staff_code_routes_no_route_limiter(): void
    {
        foreach (['portal.auth.password', 'portal.auth.terms', 'portal.sessions.destroy', 'portal.sessions.logout-others', 'portal.logins.report'] as $name) {
            $this->assertContains('throttle:portal-writes', Router::getRoutes()->getByName($name)->gatherMiddleware(), "{$name} lacks the write limit.");
        }

        foreach (['portal-admin.members.invite', 'portal-admin.members.reset-code'] as $name) {
            $limiters = array_filter(Router::getRoutes()->getByName($name)->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'throttle:portal'));
            $this->assertSame([], array_values($limiters), "{$name} meters before its permission check.");
        }
    }
}
