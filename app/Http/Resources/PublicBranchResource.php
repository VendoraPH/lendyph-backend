<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicBranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // Branch table only stores a single `address` column today, so
            // expose it under `city` to match the public registration spec.
            // If a dedicated city column is added later, swap the source here.
            'city' => $this->address,
        ];
    }
}
