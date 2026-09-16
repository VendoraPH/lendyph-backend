<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'GCashNonMember',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'full_name', type: 'string'),
        new OA\Property(property: 'mobile_number', type: 'string'),
        new OA\Property(property: 'id_type', type: 'string'),
        new OA\Property(property: 'id_number', type: 'string'),
        new OA\Property(property: 'remarks', type: 'string', nullable: true),
        new OA\Property(property: 'transaction_count', type: 'integer', nullable: true, description: 'Present only when the listing eager-counts it; never lazily fetched.'),
    ],
)]
class GCashNonMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'mobile_number' => $this->mobile_number,
            'id_type' => $this->id_type,
            'id_number' => $this->id_number,
            'remarks' => $this->remarks,
            // withCount, so the listing stays one query regardless of page size.
            'transaction_count' => $this->whenCounted('transactions'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
