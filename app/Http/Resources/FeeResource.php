<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'value' => (float) $this->value,
            'applicable_product_ids' => $this->applicable_product_ids ?? [],
            'conditions' => $this->conditions,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
