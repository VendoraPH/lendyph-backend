<?php

namespace Tests\Feature\Portal\Announcements;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\PortalAnnouncement;
use App\Models\PortalUser;
use App\Models\Role;
use App\Models\User;
use App\Services\Portal\Announcements\AnnouncementFeed;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Portal\PortalTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Announcements (portal design §7): staff draft, publish (now or later),
 * withdraw and delete drafts; a member sees the published, started and
 * unexpired ones whose audience includes them, and reads are tracked per
 * member with no fan-out.
 */
class AnnouncementsTest extends TestCase
{
    use PortalTestHelpers, SetupLendyPH;

    private PortalUser $me;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->enablePortal();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->freezeTime();
        $this->me = $this->portalMember(['branch_id' => $this->branch->id, 'member_type' => 'regular']);
        $this->token = $this->portalToken($this->me);
    }

    private function asMember(string $method, string $uri, array $body = []): TestResponse
    {
        Auth::forgetGuards();
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];

        return $this->withToken($this->token)->json($method, $uri, $body);
    }

    private function asStaff(User $user, string $method, string $uri, array $body = []): TestResponse
    {
        $this->app['auth']->setDefaultDriver('web');
        Auth::forgetGuards();
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];

        return $this->actingAs($user)->json($method, $uri, $body);
    }

    private function branchStaff(array $permissions = ['member_portal:announcements'], ?Branch $branch = null): User
    {
        $role = Role::create(['name' => 'portal_ann_'.Str::random(6), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        $user = User::factory()->inBranches($branch ?? $this->branch)->create();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    public function test_a_member_sees_only_announcements_whose_audience_includes_them(): void
    {
        $far = Branch::factory()->create();
        $visible = [
            PortalAnnouncement::factory()->create(['title' => 'All']),
            PortalAnnouncement::factory()->create(['title' => 'Mine', 'audience' => 'branch', 'branch_ids' => [$this->branch->id, $far->id], 'pinned' => true]),
            PortalAnnouncement::factory()->create(['title' => 'Regulars', 'audience' => 'member_type', 'member_types' => ['regular']]),
        ];
        PortalAnnouncement::factory()->create(['title' => 'Far', 'audience' => 'branch', 'branch_ids' => [$far->id]]);
        PortalAnnouncement::factory()->create(['title' => 'Associates', 'audience' => 'member_type', 'member_types' => ['associate']]);
        PortalAnnouncement::factory()->create(['title' => 'Later', 'publish_at' => now()->addDay()]);
        PortalAnnouncement::factory()->create(['title' => 'Over', 'expires_at' => now()->subMinute()]);
        PortalAnnouncement::factory()->create(['title' => 'Draft', 'status' => 'draft']);
        $withdrawn = PortalAnnouncement::factory()->create(['title' => 'Gone', 'status' => 'withdrawn']);

        $list = $this->asMember('GET', '/api/portal/announcements')->assertOk();
        $this->assertSame('Mine', $list->json('data.0.title'), 'Pinned first.');
        $this->assertEqualsCanonicalizing(['All', 'Mine', 'Regulars'], array_column($list->json('data'), 'title'));
        $this->assertSame(3, $list->json('meta.unread'));
        $this->assertSame(['id', 'title', 'body', 'publish_at', 'expires_at', 'pinned', 'read_at'], array_keys($list->json('data.0')));

        $this->asMember('GET', "/api/portal/announcements/{$withdrawn->id}")->assertNotFound();
        $this->asMember('POST', "/api/portal/announcements/{$visible[0]->id}/read")->assertOk();
        $this->asMember('POST', "/api/portal/announcements/{$visible[0]->id}/read")->assertOk();
        $this->assertSame(2, $this->asMember('GET', '/api/portal/announcements')->json('meta.unread'));
        $this->assertSame(2, app(AnnouncementFeed::class)->unreadCount($this->me));
    }

    public function test_staff_draft_publish_withdraw_and_delete_only_drafts(): void
    {
        $staff = $this->branchStaff();

        $draft = $this->asStaff($staff, 'POST', '/api/portal-admin/announcements', [
            'title' => 'Branch closed on Friday', 'body' => 'Our branch is closed on Friday for the holiday.',
            'audience' => 'branch', 'branch_ids' => [$this->branch->id],
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data');

        $this->assertSame(0, $this->asMember('GET', '/api/portal/announcements')->json('meta.unread'));

        $this->asStaff($staff, 'POST', "/api/portal-admin/announcements/{$draft['id']}/publish")->assertOk()->assertJsonPath('data.status', 'published');
        $this->assertSame(1, $this->asMember('GET', '/api/portal/announcements')->json('meta.unread'));
        $this->asStaff($staff, 'DELETE', "/api/portal-admin/announcements/{$draft['id']}")->assertUnprocessable();

        $this->asStaff($staff, 'POST', "/api/portal-admin/announcements/{$draft['id']}/withdraw")->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->assertSame(0, $this->asMember('GET', '/api/portal/announcements')->json('meta.unread'));

        $other = $this->asStaff($staff, 'POST', '/api/portal-admin/announcements', [
            'title' => 'x', 'body' => 'y', 'audience' => 'branch', 'branch_ids' => [$this->branch->id],
        ])->assertCreated()->json('data.id');
        $this->asStaff($staff, 'DELETE', "/api/portal-admin/announcements/{$other}")->assertOk();
        $this->assertNull(PortalAnnouncement::query()->find($other));

        foreach (['portal_announcement_created', 'portal_announcement_published', 'portal_announcement_withdrawn', 'portal_announcement_deleted'] as $action) {
            $this->assertTrue(AuditLog::query()->where('action', $action)->where('user_id', $staff->id)->exists(), $action);
        }
    }

    public function test_branch_staff_target_only_their_branches_and_company_wide_needs_view_all(): void
    {
        $staff = $this->branchStaff();
        $far = Branch::factory()->create();

        $this->asStaff($staff, 'POST', '/api/portal-admin/announcements', ['title' => 'x', 'body' => 'y', 'audience' => 'all'])->assertForbidden();
        $this->asStaff($staff, 'POST', '/api/portal-admin/announcements', ['title' => 'x', 'body' => 'y', 'audience' => 'member_type', 'member_types' => ['regular']])->assertForbidden();
        $this->asStaff($staff, 'POST', '/api/portal-admin/announcements', ['title' => 'x', 'body' => 'y', 'audience' => 'branch', 'branch_ids' => [$far->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('branch_ids.0');

        $farOne = PortalAnnouncement::factory()->create(['audience' => 'branch', 'branch_ids' => [$far->id], 'status' => 'draft']);
        $everyone = PortalAnnouncement::factory()->create(['status' => 'draft']);
        foreach ([$farOne, $everyone] as $hidden) {
            $this->asStaff($staff, 'PUT', "/api/portal-admin/announcements/{$hidden->id}", ['title' => 'z'])->assertNotFound();
            $this->asStaff($staff, 'POST', "/api/portal-admin/announcements/{$hidden->id}/publish")->assertNotFound();
        }

        $this->asStaff($staff, 'GET', '/api/portal-admin/announcements')->assertOk()->assertJsonCount(0, 'data');
        $this->asStaff($staff, 'GET', '/api/portal-admin/announcements?branch_id='.$far->id)->assertForbidden();

        $this->asStaff($this->admin, 'POST', '/api/portal-admin/announcements', ['title' => 'x', 'body' => 'y', 'audience' => 'all'])->assertCreated();
        $this->asStaff($this->branchStaff(['member_portal:requests']), 'GET', '/api/portal-admin/announcements')->assertForbidden();
    }

    public function test_announcements_switched_off_answer_403(): void
    {
        $this->enablePortal(['features' => ['announcements' => false]]);

        $this->asMember('GET', '/api/portal/announcements')->assertForbidden()->assertJsonPath('code', 'feature_off');
    }
}
