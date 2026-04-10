<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class BorrowerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $photoUrl = $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null;

        return [
            'id' => $this->id,
            'borrower_code' => $this->borrower_code,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'suffix' => $this->suffix,
            'full_name' => $this->full_name,
            'birthdate' => $this->birthdate?->toDateString(),
            'civil_status' => $this->civil_status,
            'gender' => $this->gender,
            'address' => $this->address,
            'contact_number' => $this->contact_number,
            'phone' => $this->contact_number,
            'email' => $this->email,
            'employer_or_business' => $this->employer_or_business,
            'monthly_income' => $this->monthly_income !== null ? (float) $this->monthly_income : null,
            'spouse_first_name' => $this->spouse_first_name,
            'spouse_middle_name' => $this->spouse_middle_name,
            'spouse_last_name' => $this->spouse_last_name,
            'spouse_contact_number' => $this->spouse_contact_number,
            'spouse_occupation' => $this->spouse_occupation,
            'photo_url' => $photoUrl,
            'photo' => $photoUrl,
            'status' => $this->status,
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'co_makers' => CoMakerResource::collection($this->whenLoaded('coMakers')),
            'documents' => DocumentResource::collection($this->whenLoaded('documents')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
