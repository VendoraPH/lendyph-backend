<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'GCashTier',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'min_amount', type: 'number'),
        new OA\Property(property: 'max_amount', type: 'number'),
        new OA\Property(property: 'cash_in_rate', type: 'number'),
        new OA\Property(property: 'cash_out_rate', type: 'number'),
        new OA\Property(property: 'display_order', type: 'integer'),
    ],
)]
class GCashTierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'min_amount' => (float) $this->min_amount,
            'max_amount' => (float) $this->max_amount,
            'cash_in_rate' => (float) $this->cash_in_rate,
            'cash_out_rate' => (float) $this->cash_out_rate,
            'display_order' => $this->display_order,
        ];
    }
}
