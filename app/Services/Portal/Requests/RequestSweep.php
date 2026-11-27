<?php

namespace App\Services\Portal\Requests;

use App\Models\PortalRequest;
use App\Models\PortalRequestEvent;
use App\Models\PortalUser;
use App\Models\SavingsApproval;
use App\Models\SavingsHold;
use App\Services\AuditLogService;
use App\Services\GeneratedNumberWrite;
use App\Services\Savings\Api\ApprovalDesk;
use App\Services\Savings\Api\Refuse;
use App\Services\Savings\SavingsLocks;
use App\Services\Savings\SavingsMath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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
 * - **Access ended** (any reason: PortalAccess and MemberPortalAccessEnded):
 *   the member's open requests are cancelled and their holds released, and
 *   a second-check approval is cancelled, as the system. The nightly sweep
 *   runs it again for any portal account already turned off that still has
 *   open requests (a crash, or a failure, between the commit and endFor), so
 *   no hold waits for its `expires_on`. A locked account isn't access turned
 *   off: staff unlock it, and a stranger guessing passwords must not be able
 *   to cancel a member's request.
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
        private readonly ApprovalDesk $approvals,
    ) {}

    /** @return array{ended: int, expired: int, synced: int} */
    public function run(): array
    {
        $today = now()->toDateString();
        $ended = $this->endForAccessTurnedOff();
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

        return ['ended' => $ended, 'expired' => $expired, 'synced' => $synced];
    }

    /**
     * endFor() for every portal account already turned off that still has an
     * open request or a second check waiting, with the reason it was turned
     * off. Each account is ended on its own, so one failure (reported) never
     * stops the rest; the next night retries it.
     */
    public function endForAccessTurnedOff(): int
    {
        $ended = 0;

        PortalUser::query()
            ->where('status', PortalUser::STATUS_DISABLED)
            ->whereIn('id', self::stillToEnd(PortalRequest::query())->select('portal_user_id'))
            ->orderBy('id')
            ->get()
            ->each(function (PortalUser $portalUser) use (&$ended): void {
                $ended += $this->endForReported($portalUser, $portalUser->disabled_reason ?? 'administrative');
            });

        return $ended;
    }

    /**
     * endFor(), for callers that must not fail because of it (access is
     * already off): a failure is reported and logged, and 0 is returned; the
     * nightly sweep retries. report() drops some exceptions (a 409
     * HttpResponseException from GeneratedNumberWrite, a missing model, a
     * validation error), so the warning is always written too, with ids and
     * the exception class only: no personal data, codes or tokens.
     */
    public function endForReported(PortalUser $portalUser, string $reason): int
    {
        try {
            return $this->endFor($portalUser, $reason);
        } catch (Throwable $e) {
            report($e);
            Log::warning('portal endFor failed', [
                'portal_user_id' => (int) $portalUser->getKey(),
                'request_ids' => $this->idsStillToEnd($portalUser),
                'exception' => $e::class,
            ]);

            return 0;
        }
    }

    /**
     * The requests endFor() ends: open ones (submitted, under review, need
     * info), and withdrawals waiting at a pending second check.
     *
     * @param  Builder<PortalRequest>  $query
     * @return Builder<PortalRequest>
     */
    public static function stillToEnd(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereIn('status', RequestDesk::CANCELLABLE)
            ->orWhere(fn ($q) => $q->where('status', PortalRequest::STATUS_APPROVED)
                ->whereIn('savings_approval_id', SavingsApproval::query()->select('id')->where('status', 'pending'))));
    }

    /** @return list<int>|null the member's requests still to end, or null when even that can't be read */
    private function idsStillToEnd(PortalUser $portalUser): ?array
    {
        try {
            return self::stillToEnd(PortalRequest::query())->where('portal_user_id', $portalUser->getKey())
                ->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        } catch (Throwable) {
            return null;
        }
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
     * When a member's portal access ends, for any reason (membership ended,
     * death, or staff turning it off), as the system:
     *
     * - open requests are cancelled and their holds released;
     * - a withdrawal waiting at its second check has its savings approval
     *   cancelled (ApprovalDesk), so no payout follows; the approval observer
     *   then rejects the request.
     *
     * Safe to run again: each row is re-checked under its locks.
     */
    public function endFor(PortalUser $portalUser, string $reason): int
    {
        $message = match ($reason) {
            PortalUser::REASON_DECEASED => 'Cancelled: the membership ended.',
            PortalUser::REASON_MEMBERSHIP_ENDED => 'Cancelled: the membership ended. Please contact your branch.',
            default => 'Cancelled: portal access was turned off. Please contact your branch.',
        };
        $why = in_array($reason, [PortalUser::REASON_DECEASED, PortalUser::REASON_MEMBERSHIP_ENDED], true)
            ? 'the membership ended'
            : 'portal access was turned off ('.str_replace('_', ' ', $reason).')';
        $ended = 0;

        $open = PortalRequest::query()->where('portal_user_id', $portalUser->id)
            ->whereIn('status', RequestDesk::CANCELLABLE)->orderBy('id')->pluck('id');

        foreach ($open as $id) {
            $ended += AuditLogService::withoutModelAuditing(fn (): bool => $this->cancelOne((int) $id, $message, $why)) ? 1 : 0;
        }

        $waiting = PortalRequest::query()->where('portal_user_id', $portalUser->id)
            ->where('status', PortalRequest::STATUS_APPROVED)->whereNotNull('savings_approval_id')
            ->orderBy('id')->pluck('savings_approval_id', 'id');

        foreach ($waiting as $requestId => $approvalId) {
            $approval = SavingsApproval::query()->find($approvalId);

            if ($approval === null || $approval->status !== 'pending') {
                continue;
            }

            try {
                $this->approvals->cancelAsSystem($approval, "the member's portal request was ended because {$why}.");
                $ended++;
            } catch (HttpResponseException) {
                // Already decided or past its day: the sweep settles the request.
            }

            $this->syncApproved((int) $requestId);
        }

        return $ended;
    }

    private function cancelOne(int $id, string $message, string $why): bool
    {
        return GeneratedNumberWrite::run(fn (): bool => DB::transaction(function () use ($id, $message, $why): bool {
            $request = PortalRequest::query()->findOrFail($id);

            if ($request->savings_account_id !== null) {
                $this->locks->account((int) $request->savings_account_id);
            }

            $locked = PortalRequest::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, RequestDesk::CANCELLABLE, true)) {
                return false;
            }

            $hold = $this->withdrawals->releaseHold($locked, "Request {$locked->reference} cancelled: {$why}", null);

            if ($hold !== null) {
                AuditLogService::log('portal_request_hold_released', $hold, ['status' => SavingsHold::STATUS_ACTIVE], ['status' => SavingsHold::STATUS_RELEASED],
                    'The hold of '.Refuse::peso(SavingsMath::centavos($hold->amount))." for withdrawal request {$locked->reference} was released: {$why}.", asSystem: true);
            }

            $from = $locked->status;
            $this->timeline->move($locked, PortalRequest::STATUS_CANCELLED, PortalRequestEvent::ACTOR_SYSTEM, null, $message);
            AuditLogService::log('portal_request_cancelled', $locked, ['status' => $from], ['status' => PortalRequest::STATUS_CANCELLED],
                "Portal request {$locked->reference} was cancelled: {$why}.", asSystem: true);

            return true;
        }), GeneratedNumberWrite::PORTAL_REQUEST_CONFLICT);
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
