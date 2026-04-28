<?php

namespace App\Http\Requests\CollateralType;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCollateralTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('settings:update');
    }

    public function rules(): array
    {
        $typeId = $this->route('collateralType')?->id;

        return [
            'name' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('collateral_types', 'name')->ignore($typeId),
            ],
            'detail_field_label' => ['sometimes', 'string', 'max:100'],
            'amount_field_label' => ['sometimes', 'string', 'max:100'],
            'source' => ['sometimes', Rule::in(['manual', 'share_capital'])],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'is_visible' => ['sometimes', 'boolean'],
        ];
    }
}
