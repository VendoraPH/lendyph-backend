<?php

namespace App\Services\Portal\Requests;

use App\Models\PortalRequest;
use App\Models\PortalRequestEvent;
use App\Models\PortalUser;
use App\Models\SavingsApproval;
use App\Models\SavingsHold;
use App\Services\AuditLogService;
use App\Services\GeneratedNumberWrite;
use App\Services\Savings\Api\Refuse;
use App\Services\Savings\SavingsLocks;
use App\Services\Savings\SavingsMath;
use Illuminate\Support\Facades\DB;

/**
 * The system's side of portal requests (portal design §6), each change under
 * the savings lock order (account, then request, then hold) and re-checked
 * under those locks, so running any of it twice changes nothing more:
 *
 * - **Expiry:** an open request past its `expires_on` becomes `expired`; a
 *   withdrawal's hold, if `savings:expire` hasn't expired it yet, is expired
 *   with it.
 * - **The second approval:** a withdrawal waiting at `approved` follows its
 *   savings approval: posted, it completes; rejected, cancelled or expired,
 *   it is rejected. The SavingsApproval observer calls this after commit; the
 *   nightly sweep backstops `savings:expire`'s bulk updates.
 * - **Access ended** (MemberPortalAccessEnded): the member's open requests
 *   are cancelled and their holds released, as the system.
 *
 * Every row it writes names the system (or, for a second approval decided by
 * a checker, that checker).
 */
final class RequestSweep
{
    public const NOT_APPROVED_AT_SECOND_CHECK = 'Not approved at the second check. Please contact your branch.';

    public function __construct(
        private readonly SavingsLocks $locks,
        private readonly RequestTimeline $timeline,
        private readonly WithdrawalRequests $withdrawals,
    ) {}

    /** @return array{expired: int, synced: int} */
    public function run(): array
    {
        $today = now()->toDateString();
        $expired = 0;
        $synced = 0;

        PortalRequest::query()
            ->whereIn('status', RequestDesk::CANCELLABLE)
            ->whereNotNull('expires_on')
            ->where('expires_on', '<', $today)
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $id) use (&$expired): void {
                $expired += $this->expire($id) ? 1 : 0;
            });

        PortalRequest::query()
            ->where('status', PortalRequest::STATUS_APPROVED)
            ->whereNotNull('savings_approval_id')
            ->whereIn('savings_approval_id', SavingsApproval::query()->select('id')
                ->where(fn ($q) => $q->where('status', '!=', 'pending')->orWhere('expires_at', '<=', now())))
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $id) use (&$synced): void {
                $synced += $this->syncApproved($id) ? 1 : 0;
            });

        return ['expired' => $expired, 'synced' => $synced];
    }

    /** Expire one open request past its date; false when it was already decided or isn't due. */
    public function expire(int $id): bool
    {
        return GeneratedNumberWrite::run(fn (): bool => DB::transaction(function () use ($id): bool {
            $request = PortalRequest::query()->find($id);

            if ($request === null) {
                return false;
            }

            if ($request->savings_account_id !== null) {
                $this->locks->account((int) $request->savings_account_id);
            }

            $locked = PortalRequest::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, RequestDesk::CANCELLABLE, true)
                || $locked->expires_on === null || $locked->expires_on->toDateString() >= now()->toDateString()) {
                return false;
            }

            $this->expireHold($locked);
            $from = $locked->status;
            $this->timeline->move($locked, PortalRequest::STATUS_EXPIRED, PortalRequestEvent::ACTOR_SYSTEM, null, null);

            AuditLogService::log('portal_request_expired', $locked, ['status' => $from], ['status' => PortalRequest::STATUS_EXPIRED],
                "Portal request {$locked->reference} expired after {$locked->expires_on->format('M j, Y')} without a decision.", asSystem: true);

            return true;
        }), GeneratedNumberWrite::PORTAL_REQUEST_CONFLICT);
    }

    /**
     * Follow a withdrawal's savings approval, once it is decided or lapsed.
     * False when there is nothing (more) to do.
     */
    public function syncApproved(int $id): bool
    {
        return GeneratedNumberWrite::run(fn (): bool => DB::transaction(function () use ($id): bool {
            $request = PortalRequest::query()->find($id);

            if ($request === null || $request->savings_account_id === null) {
                return false;
            }

            $this->locks->account((int) $request->savings_account_id);
            $locked = PortalRequest::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $approval = $locked->savings_approval_id === null ? null : SavingsApproval::query()->find($locked->savings_approval_id);

            if ($locked->status !== PortalRequest::STATUS_APPROVED || $approval === null) {
                return false;
            }

            if ($approval->status === 'approved' && $approval->result_transaction_id !== null) {
                $this->timeline->move($locked, PortalRequest::STATUS_COMPLETED, PortalRequestEvent::ACTOR_STAFF, $approval->decided_by, null, [
                    'savings_transaction_id' => $approval->result_transaction_id,
                ]);
                $this->audit($locked, $approval->decided_by, 'completed after its second approval');

                return true;
            }

            $lapsed = $approval->status === 'pending' && $approval->expires_at !== null && $approval->expires_at->lessThanOrEqualTo(now());

            if (! in_array($approval->status, ['rejected', 'cancelled', 'expired'], true) && ! $lapsed) {
                return false;
            }

            $byChecker = $approval->status === 'rejected' && $approval->decided_by !== null;
            $this->timeline->move($locked, PortalRequest::STATUS_REJECTED, $byChecker ? PortalRequestEvent::ACTOR_STAFF : PortalRequestEvent::ACTOR_SYSTEM,
                $byChecker ? $approval->decided_by : null, self::NOT_APPROVED_AT_SECOND_CHECK);
            $this->audit($locked, $byChecker ? $approval->decided_by : null, 'not approved at its second check');

            return true;
        }), GeneratedNumberWrite::PORTAL_REQUEST_CONFLICT);
    }

    /**
     * Cancel a member's open requests and release their holds, as the system,
     * when their portal access ended with their membership.
     */
    public function endFor(PortalUser $portalUser, string $reason): int
    {
        $ids = PortalRequest::query()->where('portal_user_id', $portalUser->id)
            ->whereIn('status', RequestDesk::CANCELLABLE)->orderBy('id')->pluck('id');
        $message = $reason === PortalUser::REASON_DECEASED
            ? 'Cancelled: the membership ended.'
            : 'Cancelled: the membership ended. Please contact your branch.';
        $cancelled = 0;

        foreach ($ids as $id) {
            $cancelled += AuditLogService::withoutModelAuditing(fn (): bool => GeneratedNumberWrite::run(fn (): bool => DB::transaction(function () use ($id, $message): bool {
                $request = PortalRequest::query()->findOrFail($id);

                if ($request->savings_account_id !== null) {
                    $this->locks->account((int) $request->savings_account_id);
                }

                $locked = PortalRequest::query()->whereKey($id)->lockForUpdate()->firstOrFail();

                if (! in_array($locked->status, RequestDesk::CANCELLABLE, true)) {
                    return false;
                }

                $hold = $this->withdrawals->releaseHold($locked, "Request {$locked->reference} cancelled: the membership ended", null);

                if ($hold !== null) {
                    AuditLogService::log('portal_request_hold_released', $hold, ['status' => SavingsHold::STATUS_ACTIVE], ['status' => SavingsHold::STATUS_RELEASED],
                        'The hold of '.Refuse::peso(SavingsMath::centavos($hold->amount))." for withdrawal request {$locked->reference} was released: the membership ended.", asSystem: true);
                }

                $from = $locked->status;
                $this->timeline->move($locked, PortalRequest::STATUS_CANCELLED, PortalRequestEvent::ACTOR_SYSTEM, null, $message);
                AuditLogService::log('portal_request_cancelled', $locked, ['status' => $from], ['status' => PortalRequest::STATUS_CANCELLED],
                    "Portal request {$locked->reference} was cancelled: the membership ended.", asSystem: true);

                return true;
            }), GeneratedNumberWrite::PORTAL_REQUEST_CONFLICT)) ? 1 : 0;
        }

        return $cancelled;
    }

    /** The hold of an expiring request, if still active: expired with it (as savings:expire would). */
    private function expireHold(PortalRequest $request): void
    {
        if ($request->savings_hold_id === null) {
            return;
        }

        $hold = SavingsHold::query()->whereKey($request->savings_hold_id)->lockForUpdate()->first();

        if ($hold === null || $hold->status !== SavingsHold::STATUS_ACTIVE) {
            return;
        }

        DB::table('savings_holds')->where('id', $hold->id)->update(['status' => SavingsHold::STATUS_EXPIRED, 'updated_at' => now()]);
        AuditLogService::log('savings_hold_expired', $hold, ['status' => SavingsHold::STATUS_ACTIVE], ['status' => SavingsHold::STATUS_EXPIRED],
            'Hold of '.Refuse::peso(SavingsMath::centavos($hold->amount))." expired with withdrawal request {$request->reference}.", asSystem: true);
    }

    private function audit(PortalRequest $request, ?int $userId, string $what): void
    {
        AuditLogService::log('portal_request_decided', $request, ['status' => PortalRequest::STATUS_APPROVED], ['status' => $request->status],
            "Portal request {$request->reference} {$what}.", $userId, asSystem: $userId === null);
    }
}
