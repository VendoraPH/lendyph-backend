<?php

namespace App\Http\Requests\Collateral;

use App\Http\Requests\Concerns\ExcludesRejectedBorrowers;
use Illuminate\Foundation\Http\FormRequest;

class StoreCollateralRequest extends FormRequest
{
    use ExcludesRejectedBorrowers;

    public function authorize(): bool
    {
        return $this->user()->can('collaterals:create');
    }

    public function rules(): array
    {
        return [
            'borrower_id' => ['required', 'integer', $this->nonRejectedBorrowerRule()],
            'collateral_type_id' => ['required', 'integer', 'exists:collateral_types,id'],
            'detail_value' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
        ];
    }
}
