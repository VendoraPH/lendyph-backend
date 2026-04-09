<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShareCapitalLedgerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'borrower_id' => $this->borrower_id,
            'borrower_name' => $this->borrower?->full_name,
            'borrower_code' => $this->borrower?->borrower_code,
            'date' => $this->date?->toDateString(),
            'description' => $this->description,
            'reference' => $this->reference,
            'debit' => (float) $this->debit,
            'credit' => (float) $this->credit,
            'created_by_user' => $this->whenLoaded('createdByUser', fn () => [
                'id' => $this->createdByUser->id,
                'name' => $this->createdByUser->full_name,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
