<?php

namespace App\Http\Requests\PenaltyConfig;

use App\Http\Requests\PenaltyConfig\Concerns\ValidatesPenaltyConfig;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PenaltyConfigInput',
    required: ['name', 'applicable_product_ids', 'conditions'],
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 100, example: 'Standard Loan Penalty'),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive'], description: 'A new configuration is active; on update, left out keeps the stored status.'),
        new OA\Property(property: 'applicable_product_ids', type: 'array', items: new OA\Items(type: 'integer'), description: 'At least one when active.'),
        new OA\Property(property: 'conditions', type: 'array', minItems: 1, maxItems: 20, description: 'Brackets from day 1 with no gaps or overlaps; only the last may be open-ended (days_to null). Rate 0 to 100, at most 4 decimals.', items: new OA\Items(properties: [
            new OA\Property(property: 'days_from', type: 'integer', example: 1),
            new OA\Property(property: 'days_to', type: 'integer', nullable: true, example: 5),
            new OA\Property(property: 'rate', type: 'number', example: 20),
        ], type: 'object')),
    ],
)]
class StorePenaltyConfigRequest extends FormRequest
{
    use ValidatesPenaltyConfig;

    public function authorize(): bool
    {
        return $this->user()->can('penalties:create');
    }

    public function rules(): array
    {
        return $this->penaltyConfigRules(null);
    }

    public function messages(): array
    {
        return $this->penaltyConfigMessages();
    }

    public function withValidator(Validator $validator): void
    {
        $this->guardPenaltyConfig($validator, null);
    }
}
