<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AmortizationScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $principalDue = (float) $this->principal_due;
        $remainingBalance = (float) $this->remaining_balance;
        $beginningBalance = round($remainingBalance + $principalDue, 2);

        return [
            'id' => $this->id,
            'period_number' => $this->period_number,
            'due_date' => $this->due_date?->toDateString(),
            'beginning_balance' => $beginningBalance,
            'principal_due' => $this->principal_due,
            'interest_due' => $this->interest_due,
            'penalty_amount' => $this->penalty_amount ?? '0.00',
            'total_due' => $this->total_due,
            'remaining_balance' => $this->remaining_balance,
            'principal_paid' => $this->principal_paid ?? '0.00',
            'interest_paid' => $this->interest_paid ?? '0.00',
            'penalty_paid' => $this->penalty_paid ?? '0.00',
            'status' => $this->status,
        ];
    }
}
