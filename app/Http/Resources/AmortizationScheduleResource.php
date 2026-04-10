<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AmortizationSchedule',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'loan_id', type: 'integer'),
        new OA\Property(property: 'period_number', type: 'integer'),
        new OA\Property(property: 'due_date', type: 'string', format: 'date'),
        new OA\Property(property: 'principal_due', type: 'number'),
        new OA\Property(property: 'interest_due', type: 'number'),
        new OA\Property(property: 'total_due', type: 'number'),
        new OA\Property(property: 'remaining_balance', type: 'number'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'paid', 'partial', 'overdue']),
        new OA\Property(property: 'principal', type: 'number', description: 'Alias for principal_due'),
        new OA\Property(property: 'interest', type: 'number', description: 'Alias for interest_due'),
        new OA\Property(property: 'amount_due', type: 'number', description: 'Alias for total_due'),
        new OA\Property(property: 'amount_paid', type: 'number', description: 'Sum of paid amounts'),
        new OA\Property(property: 'balance', type: 'number', description: 'Alias for remaining_balance'),
    ],
)]
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
