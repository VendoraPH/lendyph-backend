<?php

namespace App\Http\Requests\Collateral;

use Illuminate\Foundation\Http\FormRequest;

class AttachCollateralRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('collaterals:update') && $this->user()->can('loans:update');
    }

    public function rules(): array
    {
        return [
            'collateral_id' => ['required', 'integer', 'exists:collaterals,id'],
            'snapshot_value' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
        ];
    }
}
