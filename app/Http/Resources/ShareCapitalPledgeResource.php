<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShareCapitalPledgeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'borrower_id' => $this->borrower_id,
            'borrower_name' => $this->borrower?->full_name,
            'borrower_code' => $this->borrower?->borrower_code,
            'amount' => (float) $this->amount,
            'schedule' => $this->schedule,
            'auto_credit' => (bool) $this->auto_credit,
            'last_transaction_date' => $this->last_transaction_date,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
