<?php

namespace App\Http\Requests\Savings;

use App\Models\SavingsProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /savings/products` (savings:settings and branches:view_all). `annual_interest_rate` becomes
 * the first rate row from today (one per offered term on a time deposit
 * product). Time deposit fields are refused on a regular product. Turning it
 * on at once (`is_active`) is checked against the books by the controller.
 */
class StoreSavingsProductRequest extends FormRequest
{
    use AuthorizesCompanyWideSavings;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9-]+$/', Rule::unique('savings_products', 'code')],
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(SavingsProduct::KINDS)],
            'interest_crediting' => ['sometimes', 'required', Rule::in(SavingsProduct::CREDITING)],
            'minimum_opening_balance' => ['sometimes', 'required', ...SavingsRules::money('minimum opening balance')],
            'maintaining_balance' => ['sometimes', 'required', ...SavingsRules::money('maintaining balance')],
            'minimum_adb_for_interest' => ['sometimes', 'required', ...SavingsRules::money('minimum average daily balance for interest')],
            'term_options_days' => ['nullable', 'required_if:kind,time_deposit', 'prohibited_unless:kind,time_deposit', 'array', 'min:1', 'max:20'],
            'term_options_days.*' => ['integer', 'between:1,3650', 'distinct'],
            'pretermination_product_id' => [
                'nullable',
                'prohibited_unless:kind,time_deposit',
                'integer',
                Rule::exists('savings_products', 'id')->where('kind', SavingsProduct::KIND_REGULAR),
            ],
            'annual_interest_rate' => ['required', ...SavingsRules::rate('interest rate')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return SavingsProductMessages::all();
    }
}
