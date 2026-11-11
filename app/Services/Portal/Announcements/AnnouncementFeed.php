<?php

namespace App\Services\Portal\Announcements;

use App\Models\Borrower;
use App\Models\PortalAnnouncement;
use App\Models\PortalAnnouncementRead;
use App\Models\PortalUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What a member sees of announcements (portal design §7): published, started
 * (`publish_at` passed, Manila time) and not expired, to everyone, to the
 * member's current branch, or to the member's type. Reads are kept one row per
 * member who read one: there is no fan-out, so publishing costs nothing per
 * member. The unread count is one query (for the bell, with P2's
 * notifications count).
 */
final class AnnouncementFeed
{
    /** @return Builder<PortalAnnouncement> */
    public function visibleTo(Borrower $member): Builder
    {
        $now = now();

        return PortalAnnouncement::query()
            ->where('status', PortalAnnouncement::STATUS_PUBLISHED)
            ->where(fn (Builder $q) => $q->whereNull('publish_at')->orWhere('publish_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->where(function (Builder $q) use ($member): void {
                $q->where('audience', PortalAnnouncement::AUDIENCE_ALL);

                if ($member->branch_id !== null) {
                    $q->orWhere(fn (Builder $b) => $b->where('audience', PortalAnnouncement::AUDIENCE_BRANCH)->whereJsonContains('branch_ids', (int) $member->branch_id));
                }

                if (filled($member->member_type)) {
                    $q->orWhere(fn (Builder $t) => $t->where('audience', PortalAnnouncement::AUDIENCE_MEMBER_TYPE)->whereJsonContains('member_types', (string) $member->member_type));
                }
            });
    }

    /** One the member may see; 404 otherwise. */
    public function find(PortalUser $portalUser, int $id): PortalAnnouncement
    {
        return $this->visibleTo($portalUser->borrower)->findOrFail($id);
    }

    public function unreadCount(PortalUser $portalUser): int
    {
        return $this->visibleTo($portalUser->borrower)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('portal_announcement_reads')
                ->whereColumn('portal_announcement_reads.announcement_id', 'portal_announcements.id')
                ->where('portal_announcement_reads.portal_user_id', $portalUser->id))
            ->count();
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
