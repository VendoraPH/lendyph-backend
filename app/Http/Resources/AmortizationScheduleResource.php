<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AmortizationScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'period_number' => $this->period_number,
            'due_date' => $this->due_date?->toDateString(),
            'principal_due' => $this->principal_due,
            'interest_due' => $this->interest_due,
            'total_due' => $this->total_due,
            'remaining_balance' => $this->remaining_balance,
            'status' => $this->status,
        ];
    }
}
