<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * One in-app notification for a staff member (decision N, Arvin, 2026-11-02).
 *
 * A single parameterised class rather than one per event: every event stores
 * the same shape, and App\Services\Notifications builds the wording, so a class
 * per type would only repeat this one. The event is stored in the `type`
 * column (databaseType()), so the bell and any later filter read
 * `loan_released` rather than a PHP class name.
 *
 * Database channel only, and sent synchronously: no deployment runs a queue
 * worker, so a queued notification would never be delivered. Staff only;
 * nothing here ever reaches a borrower.
 */
class StaffNotification extends Notification
{
    public const LOAN_APPROVED = 'loan_approved';

    public const LOAN_REJECTED = 'loan_rejected';

    public const LOAN_SENT_BACK = 'loan_sent_back';

    public const APPROVAL_NEEDED = 'approval_needed';

    public const LOAN_RELEASED = 'loan_released';

    public const PAYMENT_VOIDED = 'payment_voided';

    public const LOAN_PAST_DUE = 'loan_past_due';

    public const EXTENSION_APPLIED = 'extension_applied';

    public const RESTRUCTURE_APPLIED = 'restructure_applied';

    public const ADJUSTMENT_PENDING = 'adjustment_pending';

    /**
     * @param  string  $type  one of the constants above
     * @param  string  $title  plain English, shown in bold
     * @param  string  $body  plain English, money as ₱1,500.00
     * @param  string  $url  a frontend path, e.g. /loans/12
     * @param  array<string, int|string|null>  $data  the ids the event concerns
     */
    public function __construct(
        public readonly string $type,
        public readonly string $title,
        public readonly string $body,
        public readonly string $url,
        public readonly array $data = [],
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return $this->type;
    }

    /**
     * @return array{type: string, title: string, body: string, url: string, data: array<string, int|string|null>}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'data' => $this->data,
        ];
    }
}
