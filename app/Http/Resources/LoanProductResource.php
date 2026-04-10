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
            'description' => $this->description,
            'interest_rate' => $this->interest_rate,
            'min_interest_rate' => $this->min_interest_rate,
            'max_interest_rate' => $this->interest_rate,
            'interest_method' => $this->interest_method,
            'term' => $this->term,
            'min_term' => $this->min_term,
            'max_term' => $this->max_term,
            'frequency' => $this->frequency,
            'frequencies' => $this->frequencies ?? [$this->frequency],
            'processing_fee' => $this->processing_fee,
            'min_processing_fee' => $this->min_processing_fee,
            'max_processing_fee' => $this->max_processing_fee,
            'service_fee' => $this->service_fee,
            'min_service_fee' => $this->min_service_fee,
            'max_service_fee' => $this->max_service_fee,
            'notarial_fee' => $this->notarial_fee,
            'custom_fees' => $this->custom_fees ?? [],
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
