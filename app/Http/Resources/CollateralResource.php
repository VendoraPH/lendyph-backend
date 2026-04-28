<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CollateralResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'borrower_id' => $this->borrower_id,
            'collateral_type_id' => $this->collateral_type_id,
            'detail_value' => $this->detail_value,
            'amount' => (float) $this->amount,
            'collateral_type' => $this->whenLoaded(
                'collateralType',
                fn () => new CollateralTypeResource($this->collateralType)
            ),
            'pivot' => $this->whenPivotLoaded('loan_collaterals', fn () => [
                'loan_id' => $this->pivot->loan_id,
                'snapshot_value' => (float) $this->pivot->snapshot_value,
                'attached_at' => $this->pivot->attached_at,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
