<?php

namespace App\Services\Portal\Announcements;

use App\Models\PortalAnnouncement;
use App\Models\PortalAnnouncementRead;
use App\Models\PortalUser;
use App\Services\Portal\Read\AnnouncementAudience;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The member's announcement pages (portal design §7). Who sees which, and
 * what is unread, is P2's AnnouncementAudience: the same query the bell's
 * unread count and the dashboard read, so the pages and the bell always
 * agree. Reads are kept one row per member who read one (no fan-out).
 */
final class AnnouncementFeed
{
    public function __construct(private readonly AnnouncementAudience $audience) {}

    /** @return Builder<PortalAnnouncement> */
    public function visibleTo(PortalUser $portalUser): Builder
    {
        return $this->audience->visibleTo($portalUser);
    }

    /** One the member may see; 404 otherwise. */
    public function find(PortalUser $portalUser, int $id): PortalAnnouncement
    {
        return $this->audience->visibleTo($portalUser)->findOrFail($id);
    }

    public function unreadCount(PortalUser $portalUser): int
    {
        return $this->audience->unreadBy($portalUser)->count();
    }

    /** Mark read once; reading again keeps the first time. */
    public function markRead(PortalUser $portalUser, PortalAnnouncement $announcement): PortalAnnouncementRead
    {
        return PortalAnnouncementRead::query()->firstOrCreate(
            ['announcement_id' => $announcement->id, 'portal_user_id' => $portalUser->id],
            ['read_at' => now()],
        );
    }

    /**
     * The member's read times for these announcements, by id.
     *
     * @param  Collection<int, PortalAnnouncement>  $announcements
     * @return Collection<int, mixed>
     */
    public function readTimes(PortalUser $portalUser, Collection $announcements): Collection
    {
        return PortalAnnouncementRead::query()
            ->where('portal_user_id', $portalUser->id)
            ->whereIn('announcement_id', $announcements->pluck('id'))
            ->pluck('read_at', 'announcement_id');
    }

    /** @return array{id: int, title: string, body: string, publish_at: string|null, expires_at: string|null, pinned: bool, read_at: string|null} */
    public function row(PortalAnnouncement $announcement, mixed $readAt): array
    {
        return [
            'id' => $announcement->id,
            'title' => $announcement->title,
            'body' => $announcement->body,
            'publish_at' => ($announcement->publish_at ?? $announcement->created_at)?->toJSON(),
            'expires_at' => $announcement->expires_at?->toJSON(),
            'pinned' => (bool) $announcement->pinned,
            'read_at' => $readAt === null ? null : Carbon::parse($readAt)->toJSON(),
        ];
    }
}
