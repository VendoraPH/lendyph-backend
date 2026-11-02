<?php

namespace App\Http\Requests\Fee;

use App\Http\Requests\Fee\Concerns\GuardsAgainstProductFeeOverlap;
use App\Http\Requests\Fee\Concerns\LimitsFeeValuePlaces;
use App\Http\Requests\Fee\Concerns\ValidatesFormulaFields;
use App\Models\Fee;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFeeRequest extends FormRequest
{
    use GuardsAgainstProductFeeOverlap, LimitsFeeValuePlaces, ValidatesFormulaFields;

    public function authorize(): bool
    {
        return $this->user()->can('fees:update');
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('fees', 'name')->ignore($this->fee)],
            'type' => ['sometimes', $this->feeTypeRule()],
            'value' => ['sometimes', 'numeric', 'min:0', $this->feeValuePlacesRule($this->input('type', $this->route('fee')?->type))],
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
        /** @var Fee|null $existing */
        $existing = $this->route('fee');

        $this->guardAgainstProductFeeOverlap(
            $validator,
            existingName: $existing?->name,
            existingProductIds: $existing?->applicable_product_ids,
        );

        $this->guardStoredValueOnSwitchToFixed($validator, $existing);

        $this->guardFormulaBounds($validator, $existing);
    }

    /**
     * @param  string|null  $key
     * @param  mixed  $default
     */
    public function validated($key = null, $default = null): mixed
    {
        return data_get($this->withFormulaFieldsNormalised(parent::validated(), $this->route('fee')), $key, $default);
    }
}
