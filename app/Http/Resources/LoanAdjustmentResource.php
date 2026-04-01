<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanAdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'adjustment_number' => $this->adjustment_number,
            'loan_id' => $this->loan_id,
            'loan_account_number' => $this->loan?->loan_account_number,
            'adjustment_type' => $this->adjustment_type,
            'description' => $this->description,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'status' => $this->status,
            'remarks' => $this->remarks,
            'adjusted_by_user' => $this->whenLoaded('adjustedByUser', fn () => [
                'id' => $this->adjustedByUser->id,
                'name' => $this->adjustedByUser->full_name,
            ]),
            'approved_by_user' => $this->whenLoaded('approvedByUser', fn () => $this->approvedByUser ? [
                'id' => $this->approvedByUser->id,
                'name' => $this->approvedByUser->full_name,
            ] : null),
            'approved_at' => $this->approved_at?->toDateTimeString(),
            'applied_at' => $this->applied_at?->toDateTimeString(),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
