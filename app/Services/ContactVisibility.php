<?php

namespace App\Services;

use App\Models\User;

/**
 * The round fz personal-data rule on the reminder screens (Arvin,
 * 2026-11-09): a member's contact details, even masked, the text of a pause
 * on the member, and what a provider said about the member's contact need
 * `borrowers:view` and the member's own branch. Without them:
 *
 * - a recipient and a message's `failure_reason` read null;
 * - a member pause's reason reads null, and a skip because of it reads only
 *   "Reminders are paused for this member";
 * - a skip for a contact a send proved bad reads only "Mobile number was
 *   marked invalid" (or the email's);
 * - a failure the provider worded reads null; the engine's own failure
 *   reasons (FAILURES_IN_OUR_WORDS) stay.
 */
final class ContactVisibility
{
    public const MEMBER_PAUSE = 'Reminders are paused for this member';

    /**
     * Failure codes whose reason the engine wrote itself, never a provider.
     */
    public const FAILURES_IN_OUR_WORDS = ['not_set_up', 'retry_window_closed', 'interrupted', 'error', 'driver_error', 'already_handed_over'];

    private const FLAGGED_PREFIXES = ['Mobile number was marked invalid', 'Email address was marked invalid'];

    public function __construct(private readonly BranchAccess $branches) {}

    public function allows(?User $user, ?int $memberBranchId): bool
    {
        return $user !== null
            && $user->can('borrowers:view')
            && $this->branches->canSee($user, $memberBranchId);
    }

    /**
     * A reason as the caller may read it. `$failedCode` is the row's or
     * event's reason code when it failed, so a provider's own wording is
     * dropped.
     */
    public function reason(?string $reason, bool $allowed, ?string $failedCode = null): ?string
    {
        if ($allowed || $reason === null) {
            return $reason;
        }

        if ($failedCode !== null && ! in_array($failedCode, self::FAILURES_IN_OUR_WORDS, true)) {
            return null;
        }

        foreach ([self::MEMBER_PAUSE, ...self::FLAGGED_PREFIXES] as $prefix) {
            if (str_starts_with($reason, $prefix)) {
                return $prefix;
            }
        }

        return $reason;
    }
}
