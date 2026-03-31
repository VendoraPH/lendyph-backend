<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RepaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'receipt_number' => $this->receipt_number,
            'loan_id' => $this->loan_id,
            'loan_account_number' => $this->loan?->loan_account_number,
            'payment_date' => $this->payment_date?->toDateString(),
            'amount_paid' => (float) $this->amount_paid,
            'principal_applied' => (float) $this->principal_applied,
            'interest_applied' => (float) $this->interest_applied,
            'penalty_applied' => (float) $this->penalty_applied,
            'overpayment' => (float) $this->overpayment,
            'payment_type' => $this->payment_type,
            'status' => $this->status,
            'void_reason' => $this->void_reason,
            'voided_by' => $this->voided_by,
            'voided_by_user' => $this->whenLoaded('voidedByUser', fn () => [
                'id' => $this->voidedByUser->id,
                'name' => $this->voidedByUser->full_name,
            ]),
            'voided_at' => $this->voided_at?->toDateTimeString(),
            'received_by' => $this->received_by,
            'received_by_user' => $this->whenLoaded('receivedByUser', fn () => [
                'id' => $this->receivedByUser->id,
                'name' => $this->receivedByUser->full_name,
            ]),
            'remarks' => $this->remarks,
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
