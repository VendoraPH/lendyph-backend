<?php

namespace App\Services\Reminders\Api;

use App\Models\User;
use App\Services\BranchAccess;

/**
 * The round fz personal-data rule on the reminder screens (Arvin,
 * 2026-11-09): a member's contact details, even masked, and the text of a
 * pause on the member need `borrowers:view` and the member's own branch.
 * Without them a recipient reads null, a member pause's reason reads null,
 * and a skip because of it reads only "Reminders are paused for this member".
 */
final class ContactVisibility
{
    public const MEMBER_PAUSE = 'Reminders are paused for this member';

    public function __construct(private readonly BranchAccess $branches) {}

    public function allows(?User $user, ?int $memberBranchId): bool
    {
        return $user !== null
            && $user->can('borrowers:view')
            && $this->branches->canSee($user, $memberBranchId);
    }

    /**
     * A reason as the caller may read it: a member pause's own text is cut.
     */
    public function reason(?string $reason, bool $allowed): ?string
    {
        if ($allowed || $reason === null || ! str_starts_with($reason, self::MEMBER_PAUSE)) {
            return $reason;
        }

        return self::MEMBER_PAUSE;
    }
}
