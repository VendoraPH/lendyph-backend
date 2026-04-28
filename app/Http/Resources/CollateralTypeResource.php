<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CollateralTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'detail_field_label' => $this->detail_field_label,
            'amount_field_label' => $this->amount_field_label,
            'source' => $this->source,
            'display_order' => (int) $this->display_order,
            'is_visible' => (bool) $this->is_visible,
            'is_seed' => (bool) $this->is_seed,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
