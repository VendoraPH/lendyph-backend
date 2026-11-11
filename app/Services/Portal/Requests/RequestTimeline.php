<?php

namespace App\Services\Portal\Requests;

use App\Models\PortalRequest;
use App\Models\PortalRequestEvent;
use App\Services\Portal\PortalNotices;
use Illuminate\Support\Facades\DB;

/**
 * Every move of a portal request (portal design §6): the status, a timeline
 * row (`portal_request_events`, every message member-visible) and an in-app
 * notice to the member, sent after commit by PortalNotices. A request that
 * leaves the open statuses frees its `live_key`, so the member can ask again.
 */
final class RequestTimeline
{
    public function __construct(private readonly PortalNotices $notices) {}

    /** The first timeline row of a new request, and the member's "received" notice. */
    public function opened(PortalRequest $request, ?string $remarks): void
    {
        $this->event($request, null, PortalRequest::STATUS_SUBMITTED, PortalRequestEvent::ACTOR_MEMBER, null, $remarks);
        $this->notify($request, PortalRequest::STATUS_SUBMITTED, null);
    }

    /**
     * Move a locked request to `$to`, with any extra columns to set.
     *
     * @param  string  $actor  PortalRequestEvent::ACTOR_*
     * @param  array<string, mixed>  $attributes
     */
    public function move(PortalRequest $request, string $to, string $actor, ?int $userId, ?string $message, array $attributes = []): PortalRequest
    {
        $this->assertLocked();
        $from = $request->status;

        $request->forceFill([
            ...$attributes,
            'status' => $to,
            'live_key' => in_array($to, PortalRequest::OPEN_STATUSES, true) ? $request->live_key : null,
        ])->save();

        $this->event($request, $from, $to, $actor, $userId, $message);
        $this->notify($request, $to, $message);

        return $request;
    }

    private function event(PortalRequest $request, ?string $from, string $to, string $actor, ?int $userId, ?string $message): void
    {
        PortalRequestEvent::query()->create([
            'request_id' => $request->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_type' => $actor,
            'user_id' => $actor === PortalRequestEvent::ACTOR_STAFF ? $userId : null,
            'message' => $message === null || trim($message) === '' ? null : trim($message),
        ]);
    }

    private function notify(PortalRequest $request, string $status, ?string $message): void
    {
        $portalUser = $request->portalUser;

        if ($portalUser === null) {
            return;
        }

        [$title, $body] = match ($status) {
            PortalRequest::STATUS_SUBMITTED => ['Request received', "We received your request {$request->reference}. Your branch will review it."],
            PortalRequest::STATUS_UNDER_REVIEW => ['Request under review', "Your branch is reviewing your request {$request->reference}."],
            PortalRequest::STATUS_NEED_INFO => ['More information needed', "Your branch needs more information for request {$request->reference}.".$this->quote($message)],
            PortalRequest::STATUS_APPROVED => ['Request approved', "Request {$request->reference} was approved and is waiting for a second staff check before it is paid out.".$this->quote($message)],
            PortalRequest::STATUS_COMPLETED => ['Request completed', "Your request {$request->reference} is done.".$this->quote($message)],
            PortalRequest::STATUS_REJECTED => ['Request not approved', "Your request {$request->reference} was not approved.".$this->quote($message)],
            PortalRequest::STATUS_CANCELLED => ['Request cancelled', "Your request {$request->reference} was cancelled.".$this->quote($message)],
            PortalRequest::STATUS_EXPIRED => ['Request expired', "Your request {$request->reference} expired before it was decided. You can send a new one."],
            default => ['Request updated', "Your request {$request->reference} was updated."],
        };

        $this->notices->send($portalUser, PortalNotices::CATEGORY_REQUESTS, $title, $body, '/member/requests/'.$request->id);
    }

    private function quote(?string $message): string
    {
        return $message === null || trim($message) === '' ? '' : ' '.trim($message);
    }

    private function assertLocked(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('A portal request moves only inside a transaction, under its row lock.');
        }
    }
}
