<?php

namespace App\Services\Amla\Cases;

use App\Models\AmlaInboxItem;
use App\Models\User;
use App\Services\BranchAccess;
use Illuminate\Support\Collection;

/**
 * AMLA's own inbox (AMLA design §4.14; task §15 "Deadline alerts"): items for
 * compliance staff, read only in AMLA screens. Never the general
 * notification bell, SMS or email, so nothing reaches anyone outside AMLA.
 * A message names a reference and a priority or due date, never a member.
 *
 * An item with a dedupe key is written once per user and key, so the nightly
 * deadline run can repeat safely.
 */
final class AmlaInbox
{
    public function __construct(private readonly BranchAccess $branches) {}

    /**
     * @param  iterable<User>  $users
     * @return int how many items were added
     */
    public function send(iterable $users, string $kind, string $subjectType, int $subjectId, string $message, ?string $dedupeKey = null): int
    {
        $added = 0;

        foreach ($users as $user) {
            $key = $dedupeKey === null ? null : mb_substr("{$dedupeKey}|user:{$user->id}", 0, 191);

            if ($key !== null && AmlaInboxItem::query()->where('dedupe_key', $key)->exists()) {
                continue;
            }

            AmlaInboxItem::query()->create([
                'user_id' => $user->id,
                'kind' => $kind,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'message' => mb_substr($message, 0, 255),
                'dedupe_key' => $key,
            ]);
            $added++;
        }

        return $added;
    }

    /**
     * Active users holding any of the keys who may see a record of that
     * branch: a record with no branch, or about a walk-in, or that needs head
     * office, only reaches `branches:view_all` holders.
     *
     * @param  list<string>  $permissions
     * @param  list<int>  $except
     * @return Collection<int, User>
     */
    public function recipients(array $permissions, ?int $branchId, bool $headOffice, array $except = []): Collection
    {
        return User::permission($permissions)
            ->where('status', '!=', 'inactive')
            ->whereNotIn('id', $except)
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user) => $headOffice
                ? $user->can(BranchAccess::VIEW_ALL)
                : $this->branches->canSee($user, $branchId))
            ->values();
    }
}
