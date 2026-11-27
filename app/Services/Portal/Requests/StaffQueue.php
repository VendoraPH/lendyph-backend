<?php

namespace App\Services\Portal\Requests;

use App\Models\PortalRequest;
use App\Models\PortalRequestEvent;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\BranchAccess;
use App\Services\Savings\Api\Refuse;
use App\Services\Savings\SavingsLocks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * The staff side of the requests center (portal design §4, §6):
 * branch-scoped through BranchAccess on `portal_requests.branch_id`, so
 * another branch's request answers 404 (the same as a missing one), on show
 * and on decision.
 *
 * Decisions: under review, need more information (with a message), reject
 * (with a message; a withdrawal's hold is released by the staff user), and
 * approve (by type: WithdrawalRequests, ProfileRequests, OpeningRequests).
 * Each re-checks the status under the request's lock (a stale one is 409),
 * and writes a timeline row, a member notice and a staff audit row.
 */
final class StaffQueue
{
    /** The permission each type's decision also needs (portal design §4). */
    public const TYPE_PERMISSIONS = [
        PortalRequest::TYPE_PROFILE_UPDATE => 'borrowers:update',
        PortalRequest::TYPE_SAVINGS_WITHDRAWAL => 'savings:transact',
        PortalRequest::TYPE_SAVINGS_OPENING => 'savings:create',
    ];

    public const DECISIONS = ['under_review', 'need_info', 'approve', 'reject'];

    public function __construct(
        private readonly BranchAccess $branches,
        private readonly SavingsLocks $locks,
        private readonly RequestTimeline $timeline,
        private readonly WithdrawalRequests $withdrawals,
        private readonly ProfileRequests $profiles,
        private readonly OpeningRequests $openings,
        private readonly RequestPresenter $presenter,
    ) {}

    /** @return Builder<PortalRequest> */
    public function scope(User $user): Builder
    {
        return $this->branches->constrain(PortalRequest::query(), $user, 'portal_requests.branch_id');
    }

    /** A request in the caller's branches; 404 otherwise. */
    public function find(User $user, int $id): PortalRequest
    {
        return $this->scope($user)->findOrFail($id);
    }

    /**
     * Under review, need more information or reject, as `$staff`, inside a
     * transaction (account, then request).
     *
     * @throws ValidationException
     */
    public function decide(PortalRequest $request, string $decision, ?string $message, User $staff): PortalRequest
    {
        if ($request->savings_account_id !== null) {
            $this->locks->account((int) $request->savings_account_id);
        }

        [$to, $from] = match ($decision) {
            'under_review' => [PortalRequest::STATUS_UNDER_REVIEW, [PortalRequest::STATUS_SUBMITTED, PortalRequest::STATUS_NEED_INFO]],
            'need_info' => [PortalRequest::STATUS_NEED_INFO, [PortalRequest::STATUS_SUBMITTED, PortalRequest::STATUS_UNDER_REVIEW]],
            'reject' => [PortalRequest::STATUS_REJECTED, RequestDesk::CANCELLABLE],
            default => Refuse::field('decision', 'Choose under review, need more information, approve or reject.'),
        };

        $locked = RequestDesk::lockOpen($request->id, $from, $this->why($decision));
        $before = $locked->status;

        if ($to === PortalRequest::STATUS_REJECTED) {
            $this->withdrawals->releaseHold($locked, "Request {$locked->reference} not approved", $staff);
            $this->timeline->move($locked, $to, PortalRequestEvent::ACTOR_STAFF, $staff->id, $message, ['decided_by' => $staff->id, 'decided_at' => now()]);
        } else {
            $this->timeline->move($locked, $to, PortalRequestEvent::ACTOR_STAFF, $staff->id, $message);
        }

        $this->audit($locked, $before, $staff, $message);

        return $locked;
    }

    /**
     * Approve by type, inside the savings IdempotentWrite's transaction.
     *
     * @param  array<string, mixed>  $input  the validated decision body
     * @return array{request: PortalRequest, status: int}
     *
     * @throws ValidationException
     */
    public function approve(PortalRequest $request, array $input, User $staff): array
    {
        $before = $request->status;

        if ($before === PortalRequest::STATUS_APPROVED) {
            Refuse::conflict("Request {$request->reference} is waiting for its second approval in the savings approvals queue; decide it there.");
        }

        // The staff's message to the member goes on the timeline and in the
        // notice, as on every other decision.
        $message = $input['message'] ?? null;

        [$locked, $status] = match ($request->type) {
            PortalRequest::TYPE_SAVINGS_WITHDRAWAL => (function () use ($request, $input, $staff, $message): array {
                $result = $this->withdrawals->approve($request, $input['payout'], $staff, $message);

                return [$result['request'], $result['transaction'] !== null ? 201 : 202];
            })(),
            PortalRequest::TYPE_SAVINGS_OPENING => [$this->openings->approve($request, $input['deposit'] ?? ['date' => now()->toDateString()], $staff, $message), 201],
            default => [$this->profiles->approve($request, $staff, $message), 200],
        };

        // The status the approval moved it from, as read under the row lock
        // (the timeline row the move wrote), not the copy the caller loaded.
        $from = (string) ($locked->events()->orderByDesc('id')->value('from_status') ?? $before);
        $this->audit($locked, $from, $staff, $input['message'] ?? null);

        return ['request' => $locked, 'status' => $status];
    }

    private function audit(PortalRequest $request, string $from, User $staff, ?string $message): void
    {
        AuditLogService::log('portal_request_decided', $request, ['status' => $from], array_filter([
            'status' => $request->status,
            'message' => $message,
        ], fn ($v) => $v !== null), "Portal request {$request->reference} ({$this->presenter->summary($request)}) moved from "
            .str_replace('_', ' ', $from).' to '.str_replace('_', ' ', $request->status).'.', $staff->id);
    }

    private function why(string $decision): string
    {
        return match ($decision) {
            'under_review' => 'Only a new request, or one waiting for the member\'s reply, can be put under review.',
            'need_info' => 'Ask for more information only while the request is new or under review.',
            default => 'Only an open request can be decided.',
        };
    }
}
