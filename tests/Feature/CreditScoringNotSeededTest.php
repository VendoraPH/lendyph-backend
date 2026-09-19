<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Credit Scoring permissions must not exist until Credit Scoring endpoints do.
 *
 * A complete Credit Scoring module is live on the frontend: seven sidebar items,
 * eight routed pages, eleven API paths. None of those paths exist here. Today
 * that is harmless, and the reason is thinner than it looks — the sidebar filters
 * on `can(item.permission)`, `can` reads `user.permissions` exactly as this
 * server sends them, and this server has never heard of `credit_scoring`. So the
 * whole block is dropped. RouteGuard is not a second line of defence: it reads
 * the same store, so it admits precisely the users the sidebar already offered
 * the link to.
 *
 * That means the seeder is the trigger. Add `credit_scoring:view` to the
 * permission list and seven dead menu items appear across three roles, with no
 * frontend change, no deploy and no review. Nothing in either repo would fail.
 *
 * And the module's own design spec asks for it. 2026-09-15-credit-scoring-design.md
 * says to grant `view` + `override` to loan-officer-equivalent roles and
 * `configure` to admin, with no "once the endpoints land" qualifier. Anyone
 * working that line as a to-do springs this.
 *
 * So the guard is deliberately an INVARIANT rather than a blocklist: it fails only
 * on the dangerous combination — a permission that exists while the routes do not.
 * The day the backend ships, the routes appear, this test goes quiet on its own,
 * and nobody has to remember to delete it.
 *
 * One more reason they must land together. The frontend degrades politely only on
 * 404 and 501 (use-api-resource.ts sets `unavailable` on those two alone). A
 * backend that knows the permissions but not the routes may answer 403 or 500
 * instead, and the screens render as red errors rather than an honest
 * "not connected yet".
 */
class CreditScoringNotSeededTest extends TestCase
{
    /**
     * The endpoints the frontend actually calls, from
     * `src/config/api-endpoints.ts` -> API_ENDPOINTS.CREDIT_SCORING.
     *
     * Eleven paths are declared there; only ten are reachable. ALERTS_LIST
     * (GET /credit-scoring/alerts) has no service method and no caller anywhere
     * in the frontend -- alerts arrive embedded in the risk-monitoring response
     * -- so it is a leftover constant rather than a gap, and is deliberately not
     * listed here. See docs/CREDIT_SCORING_BACKEND_HANDOFF.md in the frontend
     * repo, which says not to build it.
     */
    private const EXPECTED_ENDPOINTS = [
        'GET    /credit-scoring/dashboard',
        'GET    /credit-scoring/borrowers',
        'GET    /credit-scoring/borrowers/{borrower}',
        'GET    /credit-scoring/borrowers/{borrower}/history',
        'GET    /credit-scoring/borrowers/{borrower}/policy-flags',
        'GET    /credit-scoring/score-history',
        'GET    /credit-scoring/risk-monitoring',
        'GET    /credit-scoring/scorecard-config',
        'PUT    /credit-scoring/scorecard-config',
        'GET    /credit-scoring/settings',
        'PUT    /credit-scoring/settings',
        'POST   /credit-scoring/decisions',
    ];

    public function test_credit_scoring_permissions_are_not_granted_before_its_endpoints_exist(): void
    {
        $permissions = Permission::query()
            ->where('name', 'like', 'credit_scoring:%')
            ->orderBy('name')
            ->pluck('name')
            ->all();

        if ($permissions === []) {
            $this->assertTrue(true, 'No credit_scoring permissions seeded, which is correct until the endpoints exist.');

            return;
        }

        // Permissions exist. That is only safe if the routes do too.
        $routes = $this->creditScoringRoutes();

        $this->assertNotEmpty($routes, sprintf(
            "These credit_scoring permissions are seeded:\n\n  %s\n\n"
            ."but this API serves no /credit-scoring route at all. Granting them exposes "
            ."seven sidebar items whose pages call endpoints that answer 404 — and the "
            ."frontend only degrades politely on 404/501, so anything else (a 403 from a "
            ."permission check that exists without a route behind it, or a 500) renders as "
            ."a broken screen.\n\n"
            ."The endpoints the frontend expects:\n\n  %s\n\n"
            ."See docs/CREDIT_SCORING_BACKEND_HANDOFF.md in the frontend repo. Ship the "
            ."routes and the permissions together, or neither.",
            implode("\n  ", $permissions),
            implode("\n  ", self::EXPECTED_ENDPOINTS),
        ));
    }

    public function test_the_guard_can_actually_see_routes_when_they_exist(): void
    {
        // Without this, a broken detector would report "no routes" forever and the
        // guard above would be checking nothing. Register one and confirm it is found.
        Route::get('api/credit-scoring/__probe', fn () => null);
        Route::getRoutes()->refreshNameLookups();

        $this->assertNotEmpty(
            $this->creditScoringRoutes(),
            'The route detector failed to see a credit-scoring route that was just registered.'
        );
    }

    /**
     * @return list<string>
     */
    private function creditScoringRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->uri())
            ->filter(fn (string $uri) => str_contains($uri, 'credit-scoring'))
            ->values()
            ->all();
    }
}
