<?php

namespace Tests\Feature\Portal\Read;

use App\Models\Branch;
use App\Models\PortalAnnouncement;
use App\Models\PortalAnnouncementRead;
use App\Notifications\PortalNotice;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The member's in-app notices (portal design §1, §7): their own only, a link
 * only when it stays inside /member, and one unread count that adds the
 * announcements the member can see but hasn't opened.
 */
class PortalNotificationsTest extends TestCase
{
    use PortalReadFixtures, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->enablePortal();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_the_list_is_the_members_own_with_safe_links_and_filters(): void
    {
        $me = $this->portalMember();
        $other = $this->portalMember();
        $me->notify(new PortalNotice('security', 'New sign-in', 'Chrome on Android', '/member/security'));
        $me->notify(new PortalNotice('loans', 'Payment posted', '₱1,500.00 on LN-000001', 'https://evil.example/member'));
        $other->notify(new PortalNotice('security', 'Not yours', 'Someone else'));

        $token = $this->portalToken($me);
        $rows = $this->portalJson('GET', '/api/portal/notifications', [], $token)->assertOk()->json('data');

        $this->assertCount(2, $rows);
        $this->assertSame(['id', 'category', 'title', 'body', 'created_at', 'read_at', 'link'], array_keys($rows[0]));
        $links = collect($rows)->pluck('link', 'title');
        $this->assertSame('/member/security', $links['New sign-in']);
        $this->assertNull($links['Payment posted'], 'A link outside /member/ is dropped.');

        $security = $this->portalJson('GET', '/api/portal/notifications?category=security', [], $token)->json('data');
        $this->assertSame(['New sign-in'], array_column($security, 'title'));
    }

    public function test_read_one_read_all_and_the_unread_count(): void
    {
        $me = $this->portalMember();
        $me->notify(new PortalNotice('security', 'One', 'a'));
        $me->notify(new PortalNotice('security', 'Two', 'b'));
        $mine = PortalAnnouncement::factory()->create(['audience' => PortalAnnouncement::AUDIENCE_ALL]);
        PortalAnnouncement::factory()->create(['audience' => PortalAnnouncement::AUDIENCE_BRANCH, 'branch_ids' => [Branch::factory()->create()->id]]);
        PortalAnnouncement::factory()->create(['audience' => PortalAnnouncement::AUDIENCE_ALL, 'status' => PortalAnnouncement::STATUS_DRAFT]);
        PortalAnnouncement::factory()->create(['audience' => PortalAnnouncement::AUDIENCE_ALL, 'expires_at' => now()->subDay()]);
        PortalAnnouncement::factory()->create(['audience' => PortalAnnouncement::AUDIENCE_ALL, 'publish_at' => now()->addDay()]);
        PortalAnnouncement::factory()->create(['audience' => PortalAnnouncement::AUDIENCE_BRANCH, 'branch_ids' => [$me->borrower->branch_id]]);
        PortalAnnouncementRead::create(['announcement_id' => $mine->id, 'portal_user_id' => $me->id, 'read_at' => now()]);

        $token = $this->portalToken($me);
        $this->portalJson('GET', '/api/portal/notifications/unread-count', [], $token)
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => ['notifications' => 2, 'announcements' => 1, 'total' => 3]]);

        $id = $me->notifications()->firstOrFail()->getKey();
        $this->portalJson('POST', "/api/portal/notifications/{$id}/read", [], $token)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame(1, $me->unreadNotifications()->count());

        $this->portalJson('POST', '/api/portal/notifications/read-all', [], $token)->assertOk()->assertJsonPath('data.marked_read', 1);
        $this->assertSame(0, $me->unreadNotifications()->count());
    }

    public function test_another_members_notice_answers_404(): void
    {
        $me = $this->portalMember();
        $other = $this->portalMember();
        $other->notify(new PortalNotice('security', 'Not yours', 'x'));
        $id = $other->notifications()->firstOrFail()->getKey();

        $this->portalJson('POST', "/api/portal/notifications/{$id}/read", [], $this->portalToken($me))->assertNotFound();
        $this->assertNull($other->notifications()->firstOrFail()->read_at);
    }
}
