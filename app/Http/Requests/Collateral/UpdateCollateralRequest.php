<?php

namespace App\Http\Requests\Collateral;

use App\Http\Requests\Concerns\ExcludesRejectedBorrowers;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCollateralRequest extends FormRequest
{
    use ExcludesRejectedBorrowers;

    public function authorize(): bool
    {
        return $this->user()->can('collaterals:update');
    }

    public function rules(): array
    {
        return [
            'borrower_id' => ['sometimes', 'integer', $this->nonRejectedBorrowerRule()],
            'collateral_type_id' => ['sometimes', 'integer', 'exists:collateral_types,id'],
            'detail_value' => ['sometimes', 'nullable', 'string', 'max:255'],
            'amount' => ['sometimes', 'numeric', 'min:0', 'max:99999999.99'],
        ];
    }
}
