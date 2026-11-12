<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Fee',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'type', type: 'string', enum: ['fixed', 'percentage', 'formula']),
        new OA\Property(property: 'value', type: 'number', description: 'Pesos for a fixed fee; the rate for a percentage or recipe formula fee; 0 for a written formula'),
        new OA\Property(property: 'per_month', type: 'boolean'),
        new OA\Property(property: 'min_amount', type: 'number', nullable: true),
        new OA\Property(property: 'max_amount', type: 'number', nullable: true),
        new OA\Property(property: 'formula_expression', type: 'string', nullable: true, description: 'A formula fee written out, as saved; null for the "rate of principal" recipe and every other type'),
        new OA\Property(property: 'applicable_product_ids', type: 'array', items: new OA\Items(type: 'integer')),
        new OA\Property(property: 'conditions', type: 'object', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
)]
#[OA\Schema(
    schema: 'FeeFormulaInputs',
    description: 'What a written formula was worked out from, as strings: the snapshot on each charged item (`formula_inputs`) and the check\'s `inputs`',
    properties: [
        new OA\Property(property: 'principal_amount', type: 'string', example: '100000.00'),
        new OA\Property(property: 'loan_amount', type: 'string', example: '100000.00'),
        new OA\Property(property: 'interest_rate', type: 'string', nullable: true, example: '3.0000'),
        new OA\Property(property: 'interest_rate_frequency', type: 'string', nullable: true, example: 'monthly'),
        new OA\Property(property: 'loan_term', type: 'string', nullable: true, example: '12', description: 'Months; a days term is days ÷ 30 and keeps its decimals ("1.5")'),
        new OA\Property(property: 'term_in_days', type: 'string', nullable: true, example: '365'),
    ],
)]
class FeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'value' => (float) $this->value,
            'per_month' => (bool) $this->per_month,
            'min_amount' => $this->min_amount === null ? null : (float) $this->min_amount,
            'max_amount' => $this->max_amount === null ? null : (float) $this->max_amount,
            'formula_expression' => $this->type === 'formula' && filled($this->formula_expression) ? $this->formula_expression : null,
            'applicable_product_ids' => $this->applicable_product_ids ?? [],
            'conditions' => $this->conditions,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
