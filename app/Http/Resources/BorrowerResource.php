<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Borrower',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'borrower_code', type: 'string'),
        new OA\Property(property: 'full_name', type: 'string'),
        new OA\Property(property: 'first_name', type: 'string'),
        new OA\Property(property: 'last_name', type: 'string'),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive', 'blacklisted']),
        new OA\Property(property: 'photo_url', type: 'string', nullable: true),
        new OA\Property(property: 'photo', type: 'string', nullable: true, description: 'Alias for photo_url'),
        new OA\Property(property: 'phone', type: 'string', nullable: true, description: 'Alias for contact_number'),
        new OA\Property(property: 'monthly_income', type: 'number', nullable: true),
        new OA\Property(property: 'spouse_first_name', type: 'string', nullable: true),
        new OA\Property(property: 'spouse_last_name', type: 'string', nullable: true),
    ],
)]
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
