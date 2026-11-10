<?php

namespace App\Http\Resources\Savings;

use App\Models\SavingsProductRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of a product's rate history: the rate (a four-decimal percent,
 * never rounded), its term (null on a regular product) and the date it
 * starts.
 *
 * @mixin SavingsProductRate
 */
class SavingsProductRateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'term_days' => $this->term_days,
            'annual_rate' => $this->annual_rate,
            'effective_from' => $this->effective_from->toDateString(),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
        ];
    }
}
