<?php

namespace App\Http\Requests\Savings;

use App\Models\SavingsProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `PUT /savings/products/{product}` (savings:settings and branches:view_all). Partial. The kind
 * never changes; the code only while the product holds no account or time
 * deposit. The rate is never edited here (422): rates are appended through
 * the rates endpoint. Turning it on is checked against the books by the
 * controller. The product rules (limits, switches, eligibility) are
 * SavingsProductRuleFields'.
 */
class UpdateSavingsProductRequest extends FormRequest
{
    use AuthorizesCompanyWideSavings;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var SavingsProduct $product */
        $product = $this->route('product');
        $inUse = $product->accounts()->exists() || $product->timeDeposits()->exists();
        $isTimeDeposit = $product->isTimeDeposit();

        return [
            'code' => [
                'sometimes', 'required', 'string', 'max:32', 'regex:/^[A-Za-z0-9-]+$/',
                Rule::unique('savings_products', 'code')->ignore($product->id),
                ...($inUse ? [Rule::in([$product->code])] : []),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'kind' => ['sometimes', Rule::in([$product->kind])],
            'interest_crediting' => ['sometimes', 'required', Rule::in(SavingsProduct::CREDITING)],
            'minimum_opening_balance' => ['sometimes', 'required', ...SavingsRules::money('minimum opening balance')],
            'maintaining_balance' => ['sometimes', 'required', ...SavingsRules::money('maintaining balance')],
            'minimum_adb_for_interest' => ['sometimes', 'required', ...SavingsRules::money('minimum average daily balance for interest')],
            'term_options_days' => $isTimeDeposit ? ['sometimes', 'required', 'array', 'min:1', 'max:20'] : ['prohibited'],
            'term_options_days.*' => ['integer', 'between:1,3650', 'distinct'],
            'pretermination_product_id' => $isTimeDeposit
                ? ['sometimes', 'nullable', 'integer', Rule::exists('savings_products', 'id')->where('kind', SavingsProduct::KIND_REGULAR)]
                : ['prohibited'],
            'annual_interest_rate' => ['prohibited'],
            'is_active' => ['sometimes', 'boolean'],
            ...SavingsProductRuleFields::rules($isTimeDeposit, $this->user()),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        /** @var SavingsProduct $product */
        $product = $this->route('product');
        SavingsProductRuleFields::checkPairs($validator, $product);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...SavingsProductMessages::all(),
            ...SavingsProductRuleFields::messages(),
            'kind.in' => 'A product\'s kind can\'t be changed.',
            'code.in' => 'The code can\'t be changed once the product has accounts or time deposits.',
            'term_options_days.prohibited' => 'Only a time deposit product offers terms.',
            'pretermination_product_id.prohibited' => 'Only a time deposit product has an early termination rate.',
        ];
    }
}
