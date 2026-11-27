<?php

namespace App\Http\Resources\Reminders;

use App\Models\ReminderMessage;
use App\Services\ContactVisibility;
use App\Services\Reminders\Api\ReminderFormat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One message that was handed to a driver (frontend `ReminderMessage`).
 *
 * Only the masked recipient is ever sent, and only to a caller who may read
 * the member's details (ContactVisibility), as is the provider's
 * `failure_reason`; the full number or address stays in the table. A redacted message (two years after its loan closed) sends
 * `subject` and `body` as null, with `redacted_at`. `test_mode` is the
 * message's own stored flag: true when it was only logged.
 *
 * @mixin ReminderMessage
 */
class ReminderMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $redacted = $this->redacted_at !== null;
        $contact = app(ContactVisibility::class)->allows($request->user(), $this->borrower?->branch_id);

        return [
            'id' => $this->id,
            'queue_id' => $this->queue_item_id,
            'borrower_id' => $this->borrower_id,
            'borrower_name' => (string) $this->borrower?->full_name,
            'loan_id' => $this->loan_id,
            'loan_account_number' => ReminderFormat::loanNumber($this->loan),
            'installment_number' => $this->period_number === null ? null : (int) $this->period_number,
            'channel' => $this->channel,
            'recipient' => $contact ? $this->recipient_masked : null,
            'template_type' => $this->template_type,
            'subject' => $redacted ? null : $this->subject,
            'body' => $redacted ? null : $this->body,
            'status' => $this->status,
            'scheduled_at' => ReminderFormat::time($this->scheduled_at),
            'sent_at' => ReminderFormat::time($this->sent_at),
            'delivered_at' => ReminderFormat::time($this->delivered_at),
            'provider' => $this->provider,
            'provider_reference' => $this->provider_reference,
            'attempts' => (int) $this->attempts,
            'failure_reason' => $contact ? $this->failure_reason : null,
            'source' => $this->source,
            'created_by_name' => $this->createdBy?->full_name,
            'segments' => $this->sms_parts,
            'cost' => ReminderFormat::amount($this->cost),
            'test_mode' => (bool) $this->test_mode,
            'redacted_at' => ReminderFormat::time($this->redacted_at),
        ];
    }
}
