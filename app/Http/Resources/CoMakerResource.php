<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CoMakerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'co_maker_code' => $this->co_maker_code,
            'borrower_id' => $this->borrower_id,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'suffix' => $this->suffix,
            'full_name' => $this->full_name,
            'address' => $this->address,
            'contact_number' => $this->contact_number,
            'occupation' => $this->occupation,
            'employer' => $this->employer,
            'monthly_income' => $this->monthly_income,
            'relationship_to_borrower' => $this->relationship_to_borrower,
            'status' => $this->status,
            'borrower' => new BorrowerResource($this->whenLoaded('borrower')),
            'documents' => DocumentResource::collection($this->whenLoaded('documents')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
