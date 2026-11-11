<?php

namespace App\Services\Portal;

use App\Models\Borrower;
use App\Models\PortalCode;
use App\Models\PortalUser;
use App\Services\AuditLogService;
use App\Services\Portal\Auth\MemberNumber;
use App\Services\Portal\Auth\PortalSessions;
use Illuminate\Support\Facades\DB;

/**
 * Turning a member's portal access off, back on, unlocking it and ending its
 * sessions (portal design §1 "Revocation"). Every change revokes what it must
 * at once; `EnsurePortalMemberActive` checks again on every request, so a bulk
 * status update that bypasses model events still takes effect on the next
 * call.
 */
final class PortalAccess
{
    public function __construct(private readonly PortalSessions $sessions) {}

    /**
     * Staff turn access off (with a reason) or back on. Disabling revokes every
     * token and closes any open code.
     */
    public function disable(PortalUser $portalUser, string $reason, ?int $staffId): void
    {
        DB::transaction(function () use ($portalUser, $reason, $staffId): void {
            $locked = PortalUser::query()->lockForUpdate()->findOrFail($portalUser->getKey());

            if ($locked->status === PortalUser::STATUS_DISABLED && $locked->disabled_reason === $reason) {
                return;
            }

            $before = $locked->status;
            $locked->forceFill([
                'status' => PortalUser::STATUS_DISABLED,
                'disabled_reason' => $reason,
                'disabled_at' => now(),
                'disabled_by' => $staffId,
            ])->save();

            $this->closeOpenCodes($locked);
            $this->sessions->revoke($locked);

            AuditLogService::log(
                'portal_access_disabled',
                $locked,
                ['status' => $before],
                ['status' => PortalUser::STATUS_DISABLED, 'disabled_reason' => $reason],
                sprintf('Member portal access turned off for %s (%s)', $this->memberLabel($locked), str_replace('_', ' ', $reason)),
                $staffId,
            );
        });
    }

    public function enable(PortalUser $portalUser, int $staffId): void
    {
        DB::transaction(function () use ($portalUser, $staffId): void {
            $locked = PortalUser::query()->lockForUpdate()->findOrFail($portalUser->getKey());

            if ($locked->status !== PortalUser::STATUS_DISABLED) {
                return;
            }

            $status = $locked->password === null ? PortalUser::STATUS_INVITED : PortalUser::STATUS_ACTIVE;
            $reason = $locked->disabled_reason;
            $locked->forceFill([
                'status' => $status,
                'disabled_reason' => null,
                'disabled_at' => null,
                'disabled_by' => null,
                'failed_attempts' => 0,
                'locked_until' => null,
                'hard_locked_at' => null,
                'lockouts_cleared_at' => now(),
            ])->save();

            AuditLogService::log(
                'portal_access_enabled',
                $locked,
                ['status' => PortalUser::STATUS_DISABLED, 'disabled_reason' => $reason],
                ['status' => $status],
                sprintf('Member portal access turned back on for %s', $this->memberLabel($locked)),
                $staffId,
            );
        });
    }

    /** Clear a lockout (temporary or until-staff). Nothing to clear writes nothing. */
    public function unlock(PortalUser $portalUser, int $staffId): bool
    {
        return DB::transaction(function () use ($portalUser, $staffId): bool {
            $locked = PortalUser::query()->lockForUpdate()->findOrFail($portalUser->getKey());

            $hardLocked = $locked->isHardLocked();

            if (! $hardLocked && ! $locked->isTemporarilyLocked() && $locked->failed_attempts === 0) {
                return false;
            }

            $before = $locked->status;
            $locked->forceFill([
                'status' => $before === PortalUser::STATUS_LOCKED ? PortalUser::STATUS_ACTIVE : $before,
                'failed_attempts' => 0,
                'locked_until' => null,
                'hard_locked_at' => null,
                'lockouts_cleared_at' => now(),
            ])->save();

            AuditLogService::log(
                'portal_unlocked',
                $locked,
                ['status' => $before, 'hard_locked' => $hardLocked],
                ['status' => $locked->status],
                sprintf('Member portal account unlocked for %s', $this->memberLabel($locked)),
                $staffId,
            );

            return true;
        });
    }

    /** Staff "Sign out everywhere". */
    public function signOutEverywhere(PortalUser $portalUser, int $staffId): int
    {
        $count = $this->sessions->revoke($portalUser);

        AuditLogService::log(
            'portal_signed_out',
            $portalUser,
            null,
            ['sessions_ended' => $count],
            sprintf('Member portal sessions ended by staff for %s', $this->memberLabel($portalUser)),
            $staffId,
        );

        return $count;
    }

    /**
     * A member's status moved (the Borrower observer). Leaving `active` ends
     * every session; `withdrawn` and `deceased` also disable access for good
     * (a system change), close any open code, and announce it so the request
     * desk can cancel open requests and release their holds.
     */
    public function memberStatusChanged(Borrower $member, ?string $from, string $to): void
    {
        $portalUser = PortalUser::query()->where('borrower_id', $member->getKey())->first();

        if ($portalUser === null || $from === $to) {
            return;
        }

        if ($from === 'active') {
            $this->sessions->revoke($portalUser);
        }

        if (! in_array($to, Borrower::FORMER_MEMBER_STATUSES, true)) {
            return;
        }

        $reason = $to === 'deceased' ? PortalUser::REASON_DECEASED : PortalUser::REASON_MEMBERSHIP_ENDED;

        if ($portalUser->status !== PortalUser::STATUS_DISABLED || $portalUser->disabled_reason !== $reason) {
            $before = $portalUser->status;
            $portalUser->forceFill([
                'status' => PortalUser::STATUS_DISABLED,
                'disabled_reason' => $reason,
                'disabled_at' => now(),
                'disabled_by' => null,
            ])->save();
            $this->closeOpenCodes($portalUser);
            $this->sessions->revoke($portalUser);

            AuditLogService::log(
                'portal_access_disabled',
                $portalUser,
                ['status' => $before],
                ['status' => PortalUser::STATUS_DISABLED, 'disabled_reason' => $reason],
                sprintf('Member portal access turned off because the membership ended (%s): %s', $to, $this->memberLabel($portalUser)),
                asSystem: true,
            );
        }

        event(new MemberPortalAccessEnded($portalUser, $reason));
    }

    /** Close an open code without using it (a new code, or access turned off). */
    public function closeOpenCodes(PortalUser $portalUser): void
    {
        PortalCode::query()
            ->where('portal_user_id', $portalUser->getKey())
            ->where('status', PortalCode::STATUS_OPEN)
            ->update(['status' => PortalCode::STATUS_SUPERSEDED, 'live_key' => null, 'step_hash' => null, 'step_expires_at' => null]);
    }

    public function memberLabel(PortalUser $portalUser): string
    {
        $member = $portalUser->borrower;

        return $member === null ? "Portal user #{$portalUser->getKey()}" : sprintf('%s (%s)', $member->full_name, MemberNumber::of($member));
    }
}
