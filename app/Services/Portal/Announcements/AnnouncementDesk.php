<?php

namespace App\Services\Portal\Announcements;

use App\Models\Branch;
use App\Models\PortalAnnouncement;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\BranchAccess;
use App\Services\Savings\Api\Refuse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Staff announcements (portal design §4, §7), `member_portal:announcements`:
 *
 * - **Who sees which:** `branches:view_all` sees and targets every
 *   announcement and audience. Branch staff see and target only announcements
 *   to branches all within their own (another's answers 404), and can't use
 *   the company-wide audiences (all members, a member type: 403).
 * - **Life:** a draft is edited and published (now, or at its `publish_at`);
 *   a published one can be edited or withdrawn; a withdrawn one is kept as
 *   it is. Only a draft can be deleted.
 * - Every write is audited as the staff user.
 */
final class AnnouncementDesk
{
    public function __construct(private readonly BranchAccess $branches) {}

    /** @return Builder<PortalAnnouncement> */
    public function scope(User $user): Builder
    {
        $ids = $this->branches->for($user);
        $query = PortalAnnouncement::query();

        if ($ids === null) {
            return $query;
        }

        return $query->where('audience', PortalAnnouncement::AUDIENCE_BRANCH)
            ->whereNotNull('branch_ids')
            ->whereRaw('JSON_LENGTH(branch_ids) > 0')
            ->whereRaw('JSON_CONTAINS(?, branch_ids)', [json_encode(array_values($ids))]);
    }

    public function find(User $user, int $id): PortalAnnouncement
    {
        return $this->scope($user)->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data  validated
     */
    public function create(array $data, User $staff): PortalAnnouncement
    {
        $this->assertAudience($data, $staff);

        $announcement = PortalAnnouncement::query()->create([
            ...$this->attributes($data),
            'status' => PortalAnnouncement::STATUS_DRAFT,
            'created_by' => $staff->id,
        ]);

        $this->audit('portal_announcement_created', $announcement, null, $this->auditValues($announcement), "Announcement \"{$announcement->title}\" drafted.", $staff);

        return $announcement;
    }

    /**
     * @param  array<string, mixed>  $data  validated
     *
     * @throws ValidationException
     */
    public function update(PortalAnnouncement $announcement, array $data, User $staff): PortalAnnouncement
    {
        if ($announcement->status === PortalAnnouncement::STATUS_WITHDRAWN) {
            Refuse::field('status', 'A withdrawn announcement can\'t be edited. Draft a new one.');
        }

        $merged = [
            'audience' => $data['audience'] ?? $announcement->audience,
            'branch_ids' => array_key_exists('branch_ids', $data) ? $data['branch_ids'] : $announcement->branch_ids,
            'member_types' => array_key_exists('member_types', $data) ? $data['member_types'] : $announcement->member_types,
        ];
        $this->assertAudience($merged, $staff);

        $before = $this->auditValues($announcement);
        $announcement->fill($this->attributes([...$data, ...$merged], partial: true))->save();

        $this->audit('portal_announcement_updated', $announcement, $before, $this->auditValues($announcement), "Announcement \"{$announcement->title}\" edited.", $staff);

        return $announcement;
    }

    public function publish(PortalAnnouncement $announcement, User $staff): PortalAnnouncement
    {
        if ($announcement->status !== PortalAnnouncement::STATUS_DRAFT) {
            Refuse::conflict("This announcement is already {$announcement->status}.");
        }

        $announcement->forceFill([
            'status' => PortalAnnouncement::STATUS_PUBLISHED,
            'published_by' => $staff->id,
            'publish_at' => $announcement->publish_at ?? now(),
        ])->save();

        $this->audit('portal_announcement_published', $announcement, ['status' => PortalAnnouncement::STATUS_DRAFT], ['status' => PortalAnnouncement::STATUS_PUBLISHED],
            "Announcement \"{$announcement->title}\" published.", $staff);

        return $announcement;
    }

    public function withdraw(PortalAnnouncement $announcement, User $staff): PortalAnnouncement
    {
        if ($announcement->status !== PortalAnnouncement::STATUS_PUBLISHED) {
            Refuse::conflict("Only a published announcement can be withdrawn; this one is {$announcement->status}.");
        }

        $announcement->forceFill(['status' => PortalAnnouncement::STATUS_WITHDRAWN])->save();

        $this->audit('portal_announcement_withdrawn', $announcement, ['status' => PortalAnnouncement::STATUS_PUBLISHED], ['status' => PortalAnnouncement::STATUS_WITHDRAWN],
            "Announcement \"{$announcement->title}\" withdrawn.", $staff);

        return $announcement;
    }

    /** @throws ValidationException */
    public function delete(PortalAnnouncement $announcement, User $staff): void
    {
        if ($announcement->status !== PortalAnnouncement::STATUS_DRAFT) {
            Refuse::field('status', 'Only a draft can be deleted. Withdraw a published announcement instead.');
        }

        $this->audit('portal_announcement_deleted', $announcement, $this->auditValues($announcement), null, "Draft announcement \"{$announcement->title}\" deleted.", $staff);
        $announcement->delete();
    }

    /** @return array<string, mixed> */
    public function row(PortalAnnouncement $announcement): array
    {
        $branchIds = array_map('intval', $announcement->branch_ids ?? []);
        $now = now();

        return [
            'id' => $announcement->id,
            'title' => $announcement->title,
            'body' => $announcement->body,
            'audience' => $announcement->audience,
            'branch_ids' => $branchIds,
            'branches' => Branch::query()->whereIn('id', $branchIds)->orderBy('name')->get(['id', 'name'])->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name])->all(),
            'member_types' => array_values($announcement->member_types ?? []),
            'publish_at' => $announcement->publish_at?->toJSON(),
            'expires_at' => $announcement->expires_at?->toJSON(),
            'pinned' => (bool) $announcement->pinned,
            'status' => $announcement->status,
            'live' => $announcement->status === PortalAnnouncement::STATUS_PUBLISHED
                && ($announcement->publish_at === null || $announcement->publish_at->lessThanOrEqualTo($now))
                && ($announcement->expires_at === null || $announcement->expires_at->greaterThan($now)),
            'reads_count' => (int) ($announcement->reads_count ?? $announcement->reads()->count()),
            'created_by' => $this->person($announcement->created_by),
            'published_by' => $this->person($announcement->published_by),
            'created_at' => $announcement->created_at?->toJSON(),
            'updated_at' => $announcement->updated_at?->toJSON(),
        ];
    }

    /**
     * Company-wide audiences need `branches:view_all` (403); branch staff
     * name only their own branches (checked by the request's rules).
     *
     * @param  array<string, mixed>  $data
     */
    private function assertAudience(array $data, User $staff): void
    {
        if (($data['audience'] ?? null) !== PortalAnnouncement::AUDIENCE_BRANCH && $this->branches->for($staff) !== null) {
            throw new HttpException(403, 'Only head office can announce to every member or to a member type. Choose your branches instead.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, bool $partial = false): array
    {
        $audience = $data['audience'] ?? PortalAnnouncement::AUDIENCE_ALL;
        $attributes = [];

        foreach (['title', 'body', 'publish_at', 'expires_at'] as $field) {
            if (! $partial || array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field] ?? null;
            }
        }

        if (! $partial || array_key_exists('pinned', $data)) {
            $attributes['pinned'] = (bool) ($data['pinned'] ?? false);
        }

        $attributes['audience'] = $audience;
        $attributes['branch_ids'] = $audience === PortalAnnouncement::AUDIENCE_BRANCH ? array_values(array_unique(array_map('intval', $data['branch_ids'] ?? []))) : null;
        $attributes['member_types'] = $audience === PortalAnnouncement::AUDIENCE_MEMBER_TYPE ? array_values(array_unique($data['member_types'] ?? [])) : null;

        return $attributes;
    }

    /** @return array<string, mixed> */
    private function auditValues(PortalAnnouncement $announcement): array
    {
        return [
            'title' => $announcement->title,
            'audience' => $announcement->audience,
            'branch_ids' => $announcement->branch_ids,
            'member_types' => $announcement->member_types,
            'publish_at' => $announcement->publish_at?->toDateTimeString(),
            'expires_at' => $announcement->expires_at?->toDateTimeString(),
            'pinned' => (bool) $announcement->pinned,
            'status' => $announcement->status,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function audit(string $action, PortalAnnouncement $announcement, ?array $old, ?array $new, string $description, User $staff): void
    {
        AuditLogService::log($action, $announcement, $old, $new, $description, $staff->id);
    }

    /** @return array{id: int, name: string}|null */
    private function person(?int $userId): ?array
    {
        $user = $userId === null ? null : User::query()->find($userId);

        return $user === null ? null : ['id' => $user->id, 'name' => $user->full_name];
    }
}
