<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A request to close a branch: its status (pending, approved, rejected or
 * cancelled), effective date, reason, and who asked and decided, by name.
 */
class BranchClosureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $person = fn ($user): ?array => $user === null ? null : ['id' => $user->id, 'full_name' => $user->full_name];

        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'status' => $this->status,
            'effective_on' => $this->effective_on?->toDateString(),
            'reason' => $this->reason,
            'requested_by' => $person($this->requester),
            'requested_at' => $this->created_at,
            'decided_by' => $person($this->decider),
            'decided_at' => $this->decided_at,
            'decision_note' => $this->decision_note,
        ];
    }
}
