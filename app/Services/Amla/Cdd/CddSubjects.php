<?php

namespace App\Services\Amla\Cdd;

use App\Models\AmlaProfile;
use App\Models\Borrower;
use App\Models\GCashNonMember;
use App\Models\User;
use App\Services\BranchAccess;

/**
 * The people AMLA CDD covers (AMLA design §4.8): existing members and
 * walk-ins, never a second customer record. Co-makers are screened only
 * (AMLA-B5), so they have no CDD profile.
 *
 * Callers check `amla:cdd` first (AmlaAccess); only then is the record looked
 * up. A member in a branch the caller can't see, a pending or rejected
 * registration, and any walk-in for a caller without head-office access
 * answer the same 404 as a missing record. Walk-ins carry no branch, so they
 * need `branches:view_all`, as walk-in GCash rows with no branch do.
 */
final class CddSubjects
{
    /** @var array<string, string> the route segment for each subject type */
    public const SEGMENTS = ['members' => AmlaProfile::MEMBER, 'walk-ins' => AmlaProfile::WALK_IN];

    public function __construct(private readonly BranchAccess $branches) {}

    /** The member or walk-in, or 404. */
    public function resolve(User $user, string $segment, int $id): Borrower|GCashNonMember
    {
        $type = self::SEGMENTS[$segment] ?? null;

        abort_if($type === null, 404);

        if ($type === AmlaProfile::MEMBER) {
            return Borrower::query()->members()->visibleTo($user)->whereKey($id)->first() ?? abort(404);
        }

        abort_unless($user->can(BranchAccess::VIEW_ALL), 404);

        return GCashNonMember::query()->withTrashed()->whereKey($id)->first() ?? abort(404);
    }

    public static function type(Borrower|GCashNonMember $subject): string
    {
        return $subject instanceof Borrower ? AmlaProfile::MEMBER : AmlaProfile::WALK_IN;
    }

    /** The subject's AMLA profile, or null when CDD hasn't started. */
    public function profileFor(Borrower|GCashNonMember $subject): ?AmlaProfile
    {
        return AmlaProfile::query()
            ->where($subject instanceof Borrower ? 'borrower_id' : 'gcash_non_member_id', $subject->getKey())
            ->first();
    }

    /** Whether the user may see walk-ins at all. */
    public function seesWalkIns(User $user): bool
    {
        return $user->can(BranchAccess::VIEW_ALL);
    }
}
