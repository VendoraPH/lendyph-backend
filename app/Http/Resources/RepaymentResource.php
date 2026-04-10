<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'interest_applied' => (float) $this->interest_applied,
            'penalty_applied' => (float) $this->penalty_applied,
            'penalty_amount' => (float) $this->penalty_applied,
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
