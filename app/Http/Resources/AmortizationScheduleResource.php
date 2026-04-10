<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AmortizationScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $principalDue = (float) $this->principal_due;
        $interestDue = (float) $this->interest_due;
        $totalDue = (float) $this->total_due;
        $principalPaid = (float) ($this->principal_paid ?? 0);
        $interestPaid = (float) ($this->interest_paid ?? 0);
        $penaltyPaid = (float) ($this->penalty_paid ?? 0);
        $amountPaid = round($principalPaid + $interestPaid + $penaltyPaid, 2);
        $remainingBalance = (float) $this->remaining_balance;
        $beginningBalance = round($remainingBalance + $principalDue, 2);

        return [
            'id' => $this->id,
            'loan_id' => $this->loan_id,
            'period_number' => $this->period_number,
            'due_date' => $this->due_date?->toDateString(),
            'beginning_balance' => $beginningBalance,
            'principal_due' => $principalDue,
            'interest_due' => $interestDue,
            'penalty_amount' => (float) ($this->penalty_amount ?? 0),
            'total_due' => $totalDue,
            'remaining_balance' => $remainingBalance,
            'principal_paid' => $principalPaid,
            'interest_paid' => $interestPaid,
            'penalty_paid' => $penaltyPaid,
            'status' => $this->status,
            // Frontend-friendly aliases (matches LoanSchedule type)
            'principal' => $principalDue,
            'interest' => $interestDue,
            'amount_due' => $totalDue,
            'amount_paid' => $amountPaid,
            'balance' => $remainingBalance,
        ];
    }
}
