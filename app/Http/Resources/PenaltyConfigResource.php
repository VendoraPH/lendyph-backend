<?php

namespace App\Http\Resources;

use App\Models\LoanProduct;
use App\Models\PenaltyConfig;
use App\Models\PenaltyConfigCondition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * @mixin PenaltyConfig
 */
#[OA\Schema(
    schema: 'PenaltyConfig',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'name', type: 'string', example: 'Standard Loan Penalty'),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive']),
        new OA\Property(property: 'applicable_product_ids', type: 'array', items: new OA\Items(type: 'integer')),
        new OA\Property(property: 'products', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'id', type: 'integer'),
            new OA\Property(property: 'name', type: 'string'),
        ], type: 'object'), description: 'The products it covers, by name.'),
        new OA\Property(property: 'conditions', type: 'array', description: 'The brackets in day order: days past the due date (the day after it is day 1), `days_to` null for open-ended, and the rate in percent with up to 4 decimals, never rounded.', items: new OA\Items(properties: [
            new OA\Property(property: 'days_from', type: 'integer', example: 1),
            new OA\Property(property: 'days_to', type: 'integer', nullable: true, example: 5),
            new OA\Property(property: 'rate', type: 'number', example: 20),
        ], type: 'object')),
        new OA\Property(property: 'in_use', type: 'boolean', description: 'True once a loan was created under it: it can then be deactivated but not deleted.'),
        new OA\Property(property: 'loans_count', type: 'integer', description: 'Loans created under it.'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
)]
class PenaltyConfigResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['conditions', 'products:id,name']);
        $loans = $this->loans_count ?? $this->resource->loans()->count();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'applicable_product_ids' => $this->products->pluck('id')->sort()->values()->all(),
            'products' => $this->products->map(fn (LoanProduct $product): array => ['id' => $product->id, 'name' => $product->name])->values()->all(),
            'conditions' => $this->conditions->map(fn (PenaltyConfigCondition $condition): array => [
                'days_from' => $condition->days_from,
                'days_to' => $condition->days_to,
                'rate' => (float) $condition->rate,
            ])->values()->all(),
            'in_use' => $loans > 0,
            'loans_count' => (int) $loans,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
