<?php

namespace App\Services\Portal\Read;

use App\Models\Borrower;
use App\Models\PortalAnnouncement;
use App\Models\PortalUser;
use Illuminate\Database\Eloquent\Builder;

/**
 * The announcements a member sees (portal design §7): published, started
 * (`publish_at` passed, Manila time), not expired, and addressed to everyone,
 * to the member's current branch or to their member type. Reads are per
 * member; nothing is fanned out.
 *
 * The dashboard and the bell's unread count read it here; the announcements
 * pages (slice P3) should use the same rule.
 */
final class AnnouncementAudience
{
    /** @return Builder<PortalAnnouncement> */
    public function visibleTo(PortalUser $portalUser): Builder
    {
        /** @var Borrower $member */
        $member = $portalUser->borrower;
        $now = now();

        return PortalAnnouncement::query()
            ->where('status', PortalAnnouncement::STATUS_PUBLISHED)
            ->where(fn (Builder $q) => $q->whereNull('publish_at')->orWhere('publish_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->where(function (Builder $q) use ($member): void {
                $q->where('audience', PortalAnnouncement::AUDIENCE_ALL)
                    ->orWhere(fn (Builder $branch) => $branch
                        ->where('audience', PortalAnnouncement::AUDIENCE_BRANCH)
                        ->whereJsonContains('branch_ids', (int) $member->branch_id))
                    ->when(filled($member->member_type), fn (Builder $types) => $types->orWhere(fn (Builder $type) => $type
                        ->where('audience', PortalAnnouncement::AUDIENCE_MEMBER_TYPE)
                        ->whereJsonContains('member_types', (string) $member->member_type)));
            });
    }

    /** @return Builder<PortalAnnouncement> */
    public function unreadBy(PortalUser $portalUser): Builder
    {
        return $this->visibleTo($portalUser)
            ->whereDoesntHave('reads', fn (Builder $q) => $q->where('portal_user_id', $portalUser->getKey()));
    }
}
