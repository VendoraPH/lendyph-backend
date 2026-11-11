<?php

namespace App\Services\Portal;

use App\Models\PortalUser;
use App\Models\Role;
use App\Models\User;
use App\Notifications\StaffNotification;
use App\Services\BranchAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * In-app notices to staff about members' portal accounts (portal design §3):
 * after commit, to active staff who hold the permission through an active
 * role (admin and super_admin always count) or directly, and can see the
 * member's branch. A failure is logged and never fails the member's action.
 */
final class PortalStaffAlerts
{
    public const LOGIN_REPORTED = 'portal_login_reported';

    public function __construct(private readonly BranchAccess $branches) {}

    public function loginReported(PortalUser $portalUser): void
    {
        $member = $portalUser->borrower;

        $this->send('member_portal:manage', $member?->branch_id, fn (): StaffNotification => new StaffNotification(
            self::LOGIN_REPORTED,
            'Portal sign-in reported',
            sprintf('%s reported a portal sign-in they don\'t recognise. Their other devices were signed out; check their access card.', $member?->full_name ?? 'A member'),
            '/borrowers/'.$portalUser->borrower_id,
            ['borrower_id' => (int) $portalUser->borrower_id],
        ));
    }

    /** @param  callable(): StaffNotification  $notification */
    private function send(string $permission, ?int $branchId, callable $notification): void
    {
        try {
            DB::afterCommit(function () use ($permission, $branchId, $notification): void {
                try {
                    $recipients = $this->recipients($permission, $branchId);

                    if ($recipients->isNotEmpty()) {
                        Notification::send($recipients, $notification());
                    }
                } catch (Throwable $e) {
                    $this->logFailure($e);
                }
            });
        } catch (Throwable $e) {
            $this->logFailure($e);
        }
    }

    /** @return Collection<int, User> */
    private function recipients(string $permission, ?int $branchId): Collection
    {
        $grants = fn (Builder $permissions) => $permissions->where('name', $permission);

        return User::query()->active()
            ->where(fn (Builder $users) => $users
                ->whereHas('roles', fn (Builder $roles) => $roles
                    ->where(fn (Builder $active) => $active->where('roles.is_active', true)->orWhereIn('roles.name', Role::PROTECTED_KEYS))
                    ->whereHas('permissions', $grants))
                ->orWhereHas('permissions', $grants))
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user) => $this->branches->canSee($user, $branchId))
            ->values();
    }

    private function logFailure(Throwable $e): void
    {
        Log::error('A member portal staff notice could not be sent; the member\'s action was kept.', ['exception' => $e]);
    }
}
