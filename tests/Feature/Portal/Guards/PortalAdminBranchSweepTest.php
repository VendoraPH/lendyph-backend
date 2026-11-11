<?php

namespace Tests\Feature\Portal\Guards;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\PortalRequest;
use App\Models\PortalUser;
use App\Models\Role;
use App\Models\User;
use App\Services\BranchAccess;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Portal\PortalTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Every staff portal route is branch-scoped (portal design §4, §8): a
 * `{borrower}` or `{request}` from another branch answers 404, and a list or
 * count asked for another branch answers 403, to a caller holding every
 * permission except `branches:view_all`. Built from the live route list.
 */
class PortalAdminBranchSweepTest extends TestCase
{
    use PortalTestHelpers, SetupLendyPH;

    public function test_every_portal_admin_record_route_answers_404_for_another_branch(): void
    {
        $this->seedAndLogin();
        $this->enablePortal();
        $this->withoutMiddleware(ThrottleRequests::class);

        $far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FARP']);
        $farMember = $this->portalMember(['branch_id' => $far->id]);
        $fixtures = [
            'borrower' => $farMember->borrower_id,
            'request' => PortalRequest::factory()->create(['portal_user_id' => $farMember->id])->id,
        ];
        $caller = $this->branchCallerWithEveryOtherPermission();

        $swept = 0;
        $leaks = [];

        foreach ($this->adminRoutes() as $route) {
            $names = $route->parameterNames();

            if ($names === []) {
                continue;
            }

            $this->assertSame([], array_diff($names, array_keys($fixtures)), '/'.$route->uri().' takes a parameter this sweep has no fixture for.');

            $uri = '/'.preg_replace_callback('/\{(\w+)\??\}/', fn (array $m) => (string) $fixtures[$m[1]], $route->uri());

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->actingAs($caller);
                $status = $this->json($method, $uri, ['identity_checked_as' => 'umid', 'enabled' => false, 'reason' => 'administrative'])->status();
                $swept++;

                if ($status !== 404) {
                    $leaks[] = "{$method} /{$route->uri()} answered {$status}";
                }
            }
        }

        $this->assertGreaterThanOrEqual(7, $swept);
        $this->assertSame([], $leaks, "These portal staff routes reach another branch's record:\n  ".implode("\n  ", $leaks));
        $this->assertSame(PortalUser::STATUS_ACTIVE, $farMember->fresh()->status, 'Nothing changed on the far member.');
    }

    public function test_every_portal_admin_list_refuses_another_branch(): void
    {
        $this->seedAndLogin();
        $this->withoutMiddleware(ThrottleRequests::class);

        $far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FARP']);
        $caller = $this->branchCallerWithEveryOtherPermission();
        $swept = 0;
        $leaks = [];

        foreach ($this->adminRoutes() as $route) {
            if ($route->parameterNames() !== [] || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $this->actingAs($caller);
            $status = $this->getJson('/'.$route->uri().'?branch_id='.$far->id)->status();
            $swept++;

            if ($status !== 403) {
                $leaks[] = "GET /{$route->uri()}?branch_id={far} answered {$status}";
            }
        }

        $this->assertGreaterThanOrEqual(3, $swept);
        $this->assertSame([], $leaks, "These portal staff lists answer for another branch:\n  ".implode("\n  ", $leaks));
    }

    public function test_the_callers_own_branch_still_answers(): void
    {
        $this->seedAndLogin();
        $this->enablePortal();
        $caller = $this->branchCallerWithEveryOtherPermission();
        $mine = $this->portalMember(['branch_id' => $this->branch->id]);

        $card = $this->actingAs($caller)->getJson("/api/portal-admin/members/{$mine->borrower_id}/access");
        $this->assertSame(200, $card->status(), (string) $card->getContent());
        $card->assertJsonPath('data.state', 'active');

        $stats = $this->actingAs($caller)->getJson('/api/portal-admin/stats?branch_id='.$this->branch->id);
        $this->assertSame(200, $stats->status(), (string) $stats->getContent());
    }

    /** @return list<Route> */
    private function adminRoutes(): array
    {
        return array_values(array_filter(
            Router::getRoutes()->getRoutes(),
            fn (Route $route) => str_starts_with($route->uri(), 'api/portal-admin/'),
        ));
    }

    private function branchCallerWithEveryOtherPermission(): User
    {
        $role = Role::create(['name' => 'portal_branch_everything', 'guard_name' => 'web']);
        $role->syncPermissions(Permission::where('guard_name', 'web')->where('name', '!=', BranchAccess::VIEW_ALL)->get());

        $caller = User::factory()->inBranches($this->branch)->create();
        $caller->assignRole($role);

        return $caller->fresh();
    }
}
