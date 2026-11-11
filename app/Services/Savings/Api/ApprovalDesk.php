<?php

namespace App\Services\Savings\Api;

use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\GeneratedNumberWrite;
use App\Services\Savings\ApprovalGate;
use App\Services\Savings\Approvals\ApprovalExecutor;
use App\Services\Savings\SavingsLocks;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Decides savings approvals (design §2, §4, §10.1): approve, reject, and the
 * maker's own cancel.
 *
 * Each decision reads the approval plainly to learn what it touches, then,
 * in one transaction, locks the savings accounts (one id-ordered statement),
 * the time deposits, and only then the approval row, and re-checks under
 * those locks that it is still pending and not past `expires_at` (409
 * otherwise: a request stops reserving money at the end of its day, before
 * the nightly job marks it expired).
 *
 * - **Approve:** never the maker, never the same checker twice (403). Above
 *   tier 2 the first checker is recorded and it stays pending; the last
 *   checker carries it out through its kind's executor, which re-validates
 *   under the locks (422 rolls everything back and it stays pending).
 * - **Reject:** a checker other than the maker (the maker cancels instead).
 * - **Cancel:** the maker only.
 *
 * A close whose payout changed since it was requested (checked by the first
 * checker and again by the last) is rejected as the system in a write of its
 * own, then answered 409, so a new request can be made at once.
 *
 * Deciding frees `live_key`, so the same thing can be asked for again. A
 * lock wait timeout answers 409, never 500 (GeneratedNumberWrite::run).
 */
class ApprovalDesk
{
    public function __construct(
        private readonly SavingsLocks $locks,
        private readonly ApprovalGate $gate,
        private readonly AccountCloser $closer,
    ) {}

    /** @throws ValidationException|HttpException */
    public function approve(SavingsApproval $approval, User $checker): SavingsApproval
    {
        $executor = $this->gate->executor($approval->kind);

        try {
            return $this->carryOut($approval, $checker, $executor);
        } catch (StaleCloseRequest) {
            $this->rejectStale($approval);

            Refuse::conflict(AccountCloser::STALE_REASON.' Make a new request.');
        }
    }

    /** @throws ValidationException|HttpException|StaleCloseRequest */
    private function carryOut(SavingsApproval $approval, User $checker, ApprovalExecutor $executor): SavingsApproval
    {
        return GeneratedNumberWrite::run(fn (): SavingsApproval => DB::transaction(function () use ($approval, $checker, $executor): SavingsApproval {
            $this->locks->accounts($executor->accountIds($approval));
            $this->locks->timeDeposits($executor->timeDepositIds($approval));
            $locked = $this->lockPending($approval);
            $this->gate->assertCheckerAllowed($locked, $checker);

            if ($locked->required_checkers >= 2 && $locked->first_checker_id === null) {
                if ($locked->kind === 'close') {
                    $this->closer->assertPayoutUnchanged(SavingsAccount::query()->findOrFail($locked->account_id), $locked);
                }

                $locked->update(['first_checker_id' => $checker->id]);

                return $locked;
            }

            $result = $executor->execute($locked, $checker);

            $locked->update([
                'status' => 'approved',
                'decided_by' => $checker->id,
                'decided_at' => now(),
                'live_key' => null,
                'result_transaction_id' => $result?->id,
            ]);

            return $locked;
        }), GeneratedNumberWrite::SAVINGS_CONFLICT);
    }

    /**
     * Reject a stale close request as the system, in its own committed write,
     * so it stops blocking the account and reserving its payout at once.
     */
    private function rejectStale(SavingsApproval $approval): void
    {
        GeneratedNumberWrite::run(fn () => DB::transaction(function () use ($approval): void {
            $this->locks->accounts(array_filter([$approval->account_id]));
            $locked = SavingsApproval::query()->whereKey($approval->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'pending') {
                $locked->update([
                    'status' => 'rejected',
                    'decided_by' => null,
                    'decision_reason' => AccountCloser::STALE_REASON,
                    'decided_at' => now(),
                    'live_key' => null,
                ]);
            }
        }), GeneratedNumberWrite::SAVINGS_CONFLICT);
    }

    /** @throws HttpException */
    public function reject(SavingsApproval $approval, string $reason, User $checker): SavingsApproval
    {
        return $this->decide($approval, function (SavingsApproval $locked) use ($reason, $checker): void {
            if ($locked->requested_by === $checker->id) {
                throw new HttpException(403, 'You made this request. Cancel it instead of rejecting it.');
            }

            $locked->update([
                'status' => 'rejected',
                'decided_by' => $checker->id,
                'decision_reason' => $reason,
                'decided_at' => now(),
                'live_key' => null,
            ]);
        });
    }

    /** @throws HttpException */
    public function cancel(SavingsApproval $approval, ?string $reason, User $maker): SavingsApproval
    {
        if ($approval->requested_by !== $maker->id) {
            throw new HttpException(403, 'Only the person who made this request can cancel it.');
        }

        return $this->decide($approval, fn (SavingsApproval $locked) => $locked->update([
            'status' => 'cancelled',
            'decided_by' => $maker->id,
            'decision_reason' => $reason,
            'decided_at' => now(),
            'live_key' => null,
        ]));
    }

    /**
     * Cancel a pending approval as the system (no maker, no checker), with a
     * reason in words: a member's portal request whose access ended (portal
     * design §1). The automatic audit row would name whoever's request set it
     * off, so it is replaced by one system row. 409 when it is no longer
     * pending or its day has passed (the nightly jobs settle those).
     *
     * @throws HttpResponseException
     */
    public function cancelAsSystem(SavingsApproval $approval, string $reason): SavingsApproval
    {
        return AuditLogService::withoutModelAuditing(function () use ($approval, $reason): SavingsApproval {
            $cancelled = $this->decide($approval, fn (SavingsApproval $locked) => $locked->update([
                'status' => 'cancelled',
                'decided_by' => null,
                'decision_reason' => $reason,
                'decided_at' => now(),
                'live_key' => null,
            ]));

            AuditLogService::log('savings_approval_cancelled', $cancelled, ['status' => 'pending'], ['status' => 'cancelled'],
                "Approval request #{$cancelled->id} was cancelled: {$reason}", asSystem: true);

            return $cancelled;
        });
    }

    /**
     * @param  callable(SavingsApproval): void  $write
     */
    private function decide(SavingsApproval $approval, callable $write): SavingsApproval
    {
        return GeneratedNumberWrite::run(fn (): SavingsApproval => DB::transaction(function () use ($approval, $write): SavingsApproval {
            $this->locks->accounts(array_filter([$approval->account_id]));
            $this->locks->timeDeposits(array_filter([$approval->time_deposit_id]));
            $locked = $this->lockPending($approval);
            $write($locked);

            return $locked;
        }), GeneratedNumberWrite::SAVINGS_CONFLICT);
    }

    /** The approval row, locked, still pending and still within its day; 409 otherwise. */
    private function lockPending(SavingsApproval $approval): SavingsApproval
    {
        $locked = SavingsApproval::query()->whereKey($approval->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== 'pending') {
            Refuse::conflict("This request is already {$locked->status}.");
        }

        if ($locked->expires_at !== null && $locked->expires_at->lessThanOrEqualTo(now())) {
            Refuse::conflict('This request expired at the end of the day it was made, so it can no longer be decided. Make a new request.');
        }

        return $locked;
    }
}
