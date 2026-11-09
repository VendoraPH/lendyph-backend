<?php

namespace App\Http\Resources\Reminders;

use App\Models\ReminderPause;
use App\Services\ContactVisibility;
use App\Services\Reminders\Api\ReminderFormat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A pause on automated reminders (frontend `ReminderPause`), naming the member
 * by id and name only. A member's or a loan's pause reason is null for a
 * caller who may not read that member's details (ContactVisibility): a loan
 * pause is judged by its loan's member. A company-wide pause's reason stays.
 *
 * @mixin ReminderPause
 */
class ReminderPauseResource extends JsonResource
{
    public const RELATIONS = ['borrower:id,branch_id,first_name,middle_name,last_name,suffix', 'loan:id,borrower_id,loan_account_number,application_number', 'loan.borrower:id,branch_id', 'pausedBy:id,first_name,last_name'];

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
            'reason' => $this->hidesReasonFrom($request) ? null : $this->reason,
            'paused_by_name' => $this->pausedBy?->full_name ?? 'System',
            'paused_at' => ReminderFormat::time($this->paused_at),
            'until' => ReminderFormat::time($this->until),
        ];
    }

    private function hidesReasonFrom(Request $request): bool
    {
        $memberBranchId = match ($this->scope) {
            'borrower' => $this->borrower?->branch_id,
            'loan' => $this->loan?->borrower?->branch_id,
            default => null,
        };

        return $this->scope !== 'global' && ! app(ContactVisibility::class)->allows($request->user(), $memberBranchId);
    }
}
