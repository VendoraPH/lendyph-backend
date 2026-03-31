<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'interest_rate' => $this->interest_rate,
            'interest_method' => $this->interest_method,
            'term' => $this->term,
            'frequency' => $this->frequency,
            'processing_fee' => $this->processing_fee,
            'service_fee' => $this->service_fee,
            'penalty_rate' => $this->penalty_rate,
            'grace_period_days' => $this->grace_period_days,
            'min_amount' => $this->min_amount,
            'max_amount' => $this->max_amount,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
