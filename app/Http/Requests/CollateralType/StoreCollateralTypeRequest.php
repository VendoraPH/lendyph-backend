<?php

namespace App\Http\Requests\CollateralType;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCollateralTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('settings:update');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('collateral_types', 'name')],
            'detail_field_label' => ['required', 'string', 'max:100'],
            'amount_field_label' => ['required', 'string', 'max:100'],
            'source' => ['nullable', Rule::in(['manual', 'share_capital'])],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'is_visible' => ['nullable', 'boolean'],
        ];
    }
}
