<?php

namespace App\Services\Portal\Requests;

use App\Models\PortalRequest;
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
 * Tells branch staff about a member's new portal request (portal design §6):
 * after commit, in-app only, to active staff who hold
 * `member_portal:requests` (through an active role, admin and super_admin
 * always, or directly) and can see the request's branch. The member is never
 * a recipient and never the actor here. A failure is logged and never fails
 * the member's request.
 */
final class RequestAlerts
{
    public const SUBMITTED = 'portal_request_submitted';

    public const PERMISSION = 'member_portal:requests';

    public function __construct(private readonly BranchAccess $branches) {}

    public function submitted(PortalRequest $request, string $summary): void
    {
        $branchId = $request->branch_id;
        $notification = fn (): StaffNotification => new StaffNotification(
            self::SUBMITTED,
            'New member portal request',
            sprintf('%s sent request %s: %s.', $request->borrower?->full_name ?? 'A member', $request->reference, $summary),
            '/member-portal/requests/'.$request->id,
            ['portal_request_id' => (int) $request->id],
        );

        try {
            DB::afterCommit(function () use ($branchId, $notification): void {
                try {
                    $recipients = $this->recipients($branchId);

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
    private function recipients(?int $branchId): Collection
    {
        $grants = fn (Builder $permissions) => $permissions->where('name', self::PERMISSION);

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
        Log::error('A member portal request notice to staff could not be sent; the request was kept.', ['exception' => $e]);
    }
}
