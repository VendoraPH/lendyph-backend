<?php

namespace App\Http\Requests\Fee;

use App\Http\Requests\Fee\Concerns\GuardsAgainstProductFeeOverlap;
use App\Http\Requests\Fee\Concerns\LimitsFeeValuePlaces;
use App\Http\Requests\Fee\Concerns\ValidatesFormulaFields;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreFeeRequest extends FormRequest
{
    use GuardsAgainstProductFeeOverlap, LimitsFeeValuePlaces, ValidatesFormulaFields;

    public function authorize(): bool
    {
        return $this->user()->can('fees:create');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:fees,name'],
            'type' => ['required', $this->feeTypeRule()],
            'value' => ['required', 'numeric', 'min:0', $this->feeValuePlacesRule($this->input('type'))],
            ...$this->formulaFieldRules(),
            'applicable_product_ids' => ['nullable', 'array'],
            'applicable_product_ids.*' => ['integer', 'exists:loan_products,id'],
            'conditions' => ['nullable', 'array'],
            'conditions.term_days_gt' => ['nullable', 'integer', 'min:0'],
            'conditions.term_days_lt' => ['nullable', 'integer', 'min:0'],
            'conditions.term_days_eq' => ['nullable', 'integer', 'min:0'],
            'conditions.loan_amount_gt' => ['nullable', 'numeric', 'min:0'],
            'conditions.loan_amount_lt' => ['nullable', 'numeric', 'min:0'],
            'conditions.loan_amount_eq' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->guardAgainstProductFeeOverlap($validator);
        $this->guardFormulaBounds($validator);
    }

    /**
     * @param  string|null  $key
     * @param  mixed  $default
     */
    public function validated($key = null, $default = null): mixed
    {
        return data_get($this->withFormulaFieldsNormalised(parent::validated()), $key, $default);
    }
}
