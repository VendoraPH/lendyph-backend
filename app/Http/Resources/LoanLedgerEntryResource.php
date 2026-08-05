<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'LoanLedgerEntry',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'type', type: 'string', enum: ['debit', 'credit'], description: 'debit increases what the borrower owes; credit reduces it'),
        new OA\Property(property: 'category', type: 'string', enum: ['principal', 'interest', 'penalty']),
        new OA\Property(property: 'amount', type: 'number'),
        new OA\Property(property: 'entry_date', type: 'string', format: 'date'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'loan_adjustment_id', type: 'integer', nullable: true, description: 'The extension this entry belongs to'),
        new OA\Property(property: 'repayment_id', type: 'integer', nullable: true, description: 'Set on a credit that settled a repayment, so the client can avoid listing the same money twice'),
    ],
)]
class LoanLedgerEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'category' => $this->category,
            'amount' => (float) $this->amount,
            'entry_date' => $this->entry_date?->toDateString(),
            'description' => $this->description,
            'loan_adjustment_id' => $this->loan_adjustment_id,
            'repayment_id' => $this->repayment_id,
            'created_at' => $this->created_at,
        ];
    }
}
