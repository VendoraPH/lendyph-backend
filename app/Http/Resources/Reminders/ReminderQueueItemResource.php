<?php

namespace App\Http\Resources\Reminders;

use App\Models\ReminderQueueItem;
use App\Services\Reminders\Api\ReminderFormat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One reminder in the queue (frontend `ReminderQueueItem`). The recipient is
 * always masked: the one stored when it was handed over, else the member's
 * current contact, masked. `test_mode` is its message's own, null before one
 * exists. `reason_code` is the engine's code as stored.
 *
 * @mixin ReminderQueueItem
 */
class ReminderQueueItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'borrower_id' => $this->borrower_id,
            'borrower_name' => (string) $this->borrower?->full_name,
            'loan_id' => $this->loan_id,
            'loan_account_number' => ReminderFormat::loanNumber($this->loan),
            'installment_number' => (int) $this->period_number,
            'due_date' => ReminderFormat::date($this->due_date),
            'amount_due' => ReminderFormat::amount($this->amount_due),
            'rule_id' => $this->rule_id,
            'rule_name' => $this->rule?->name,
            'channel' => $this->channel,
            'recipient' => $this->recipient_masked ?? ReminderFormat::maskedContact($this->borrower, $this->channel),
            'scheduled_at' => ReminderFormat::time($this->scheduled_at),
            'status' => $this->status,
            'reason' => $this->reason,
            'reason_code' => $this->reason_code,
            'attempts' => (int) $this->attempts,
            'source' => $this->source,
            'test_mode' => $this->message?->test_mode,
        ];
    }
}
