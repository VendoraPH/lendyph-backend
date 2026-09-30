<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * One member's group on the Collateral Register: a row from
 * CollateralRegister::groups(), with its valued collateral rows attached by
 * the controller as `collaterals`.
 *
 * Every figure covers the group's FILTERED rows only, which are exactly the
 * rows in `collaterals`.
 */
#[OA\Schema(
    schema: 'CollateralRegisterGroup',
    properties: [
        new OA\Property(property: 'borrower_id', type: 'integer'),
        new OA\Property(property: 'borrower_name', type: 'string', description: 'The member\'s full name, or "Member #{id}" when the caller lacks `borrowers:view` (then `meta.names_hidden` is true).'),
        new OA\Property(property: 'collaterals_count', type: 'integer', description: 'Rows in this group after search and type filtering.'),
        new OA\Property(property: 'tagged_count', type: 'integer', description: 'Rows held by at least one loan in an active status.'),
        new OA\Property(property: 'total_value', type: 'number', description: 'Sum of `effective_value` over the rows whose value is known, rounded to 2 decimals.'),
        new OA\Property(property: 'unknown_count', type: 'integer', description: 'Rows whose `value_unknown` is true, left out of `total_value`.'),
        new OA\Property(property: 'collaterals', type: 'array', description: 'The rows, newest first, valued and with `collateral_type` and `active_loans`.', items: new OA\Items(ref: '#/components/schemas/Collateral')),
    ],
)]
class CollateralRegisterGroupResource extends JsonResource
{
    /**
     * @return array{
     *     borrower_id: int,
     *     borrower_name: string,
     *     collaterals_count: int,
     *     tagged_count: int,
     *     total_value: float,
     *     unknown_count: int,
     *     collaterals: AnonymousResourceCollection,
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'borrower_id' => (int) $this->borrower_id,
            'borrower_name' => (string) $this->borrower_name,
            'collaterals_count' => (int) $this->collaterals_count,
            'tagged_count' => (int) $this->tagged_count,
            'total_value' => round((float) $this->total_value, 2),
            'unknown_count' => (int) $this->unknown_count,
            'collaterals' => $this->collaterals,
        ];
    }
}
