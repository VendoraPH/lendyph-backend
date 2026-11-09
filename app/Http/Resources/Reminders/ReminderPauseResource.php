<?php

namespace App\Http\Resources\Reminders;

use App\Models\ReminderPause;
use App\Services\Reminders\Api\ReminderFormat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A pause on automated reminders (frontend `ReminderPause`), naming the member
 * by id and name only.
 *
 * @mixin ReminderPause
 */
class ReminderPauseResource extends JsonResource
{
    public const RELATIONS = ['borrower:id,first_name,middle_name,last_name,suffix', 'loan:id,loan_account_number,application_number', 'pausedBy:id,first_name,last_name'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scope' => $this->scope,
            'borrower_id' => $this->borrower_id,
            'borrower_name' => $this->borrower?->full_name,
            'loan_id' => $this->loan_id,
            'loan_account_number' => $this->loan === null ? null : ReminderFormat::loanNumber($this->loan),
            'reason' => $this->reason,
            'paused_by_name' => $this->pausedBy?->full_name ?? 'System',
            'paused_at' => ReminderFormat::time($this->paused_at),
            'until' => ReminderFormat::time($this->until),
        ];
    }
}
