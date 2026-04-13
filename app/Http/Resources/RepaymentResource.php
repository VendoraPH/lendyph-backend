<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Repayment',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'receipt_number', type: 'string'),
        new OA\Property(property: 'loan_id', type: 'integer'),
        new OA\Property(property: 'payment_date', type: 'string', format: 'date'),
        new OA\Property(property: 'method', type: 'string', enum: ['cash', 'gcash', 'maya', 'bank_transfer', 'online']),
        new OA\Property(property: 'reference_number', type: 'string', nullable: true),
        new OA\Property(property: 'amount_paid', type: 'number'),
        new OA\Property(property: 'amount', type: 'number', description: 'Alias for amount_paid'),
        new OA\Property(property: 'principal_applied', type: 'number'),
        new OA\Property(property: 'interest_applied', type: 'number'),
        new OA\Property(property: 'penalty_applied', type: 'number'),
        new OA\Property(property: 'overdue_interest_applied', type: 'number', description: 'Interest paid on overdue schedules'),
        new OA\Property(property: 'current_interest_applied', type: 'number', description: 'Interest paid on the current period'),
        new OA\Property(property: 'current_principal_applied', type: 'number', description: 'Principal paid on the current period'),
        new OA\Property(property: 'next_interest_applied', type: 'number', description: 'Excess interest flowed to next schedule (0 when scb_amount>0)'),
        new OA\Property(property: 'next_principal_applied', type: 'number', description: 'Excess principal flowed to next schedule (0 when scb_amount>0)'),
        new OA\Property(property: 'overpayment', type: 'number', description: 'Unallocated remainder (frontend routes to SCB when scb_amount>0)'),
        new OA\Property(property: 'balance_before', type: 'number'),
        new OA\Property(property: 'balance_after', type: 'number'),
        new OA\Property(property: 'status', type: 'string', enum: ['completed', 'voided']),
        new OA\Property(property: 'collected_by', type: 'string', nullable: true),
        new OA\Property(property: 'paid_at', type: 'string', format: 'date'),
    ],
)]
class RepaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Map backend status 'posted' to frontend 'completed' for badge color matching
        $frontendStatus = $this->status === 'posted' ? 'completed' : $this->status;

        return [
            'id' => $this->id,
            'receipt_number' => $this->receipt_number,
            'loan_id' => $this->loan_id,
            'borrower_id' => $this->loan?->borrower_id,
            'loan_account_number' => $this->loan?->loan_account_number,
            'payment_date' => $this->payment_date?->toDateString(),
            'paid_at' => $this->payment_date?->toDateString(),
            'method' => $this->method,
            'reference_number' => $this->reference_number,
            'amount_paid' => (float) $this->amount_paid,
            'amount' => (float) $this->amount_paid,
            'principal_applied' => (float) $this->principal_applied,
            'principal_amount' => (float) $this->principal_applied,
            'interest_applied' => (float) $this->interest_applied,
            'interest_amount' => (float) $this->interest_applied,
            'penalty_applied' => (float) $this->penalty_applied,
            'penalty_amount' => (float) $this->penalty_applied,
            'overdue_interest_applied' => (float) $this->overdue_interest_applied,
            'current_interest_applied' => (float) $this->current_interest_applied,
            'current_principal_applied' => (float) $this->current_principal_applied,
            'next_interest_applied' => (float) $this->next_interest_applied,
            'next_principal_applied' => (float) $this->next_principal_applied,
            'overpayment' => (float) $this->overpayment,
            'balance_before' => (float) $this->balance_before,
            'balance_after' => (float) $this->balance_after,
            'payment_type' => $this->payment_type,
            'status' => $frontendStatus,
            'void_reason' => $this->void_reason,
            'voided_by' => $this->voided_by,
            'voided_by_user' => $this->whenLoaded('voidedByUser', fn () => [
                'id' => $this->voidedByUser->id,
                'name' => $this->voidedByUser->full_name,
            ]),
            'voided_at' => $this->voided_at?->toDateTimeString(),
            'received_by' => $this->received_by,
            // collected_by must be a string for frontend display (full_name of receiving user)
            'collected_by' => $this->whenLoaded('receivedByUser', fn () => $this->receivedByUser->full_name),
            'received_by_user' => $this->whenLoaded('receivedByUser', fn () => [
                'id' => $this->receivedByUser->id,
                'name' => $this->receivedByUser->full_name,
            ]),
            'remarks' => $this->remarks,
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
