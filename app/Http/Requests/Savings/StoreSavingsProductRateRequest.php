<?php

namespace App\Http\Requests\Savings;

use App\Models\SavingsProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /savings/products/{product}/rates` (savings:settings and branches:view_all): a new rate from
 * a date, today or later. A regular product's rate has no term; a time
 * deposit product's names one of the terms it offers. Rates are only ever
 * appended: there is no route to change or remove one.
 */
class StoreSavingsProductRateRequest extends FormRequest
{
    use AuthorizesCompanyWideSavings;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var SavingsProduct $product */
        $product = $this->route('product');

        return [
            'annual_rate' => ['required', ...SavingsRules::rate('interest rate')],
            'term_days' => $product->isTimeDeposit()
                ? ['required', 'integer', Rule::in(array_map('intval', $product->term_options_days ?? []))]
                : ['prohibited'],
            'effective_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now()->toDateString()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...SavingsRules::messages([], ['annual_rate' => 'interest rate']),
            'annual_rate.required' => 'Enter the interest rate.',
            'term_days.required' => 'Choose which term this rate is for.',
            'term_days.integer' => 'The term is a whole number of days.',
            'term_days.in' => 'The product doesn\'t offer that term. Add the term to the product first.',
            'term_days.prohibited' => 'A regular savings rate has no term.',
            'effective_from.required' => 'Enter the date the rate starts.',
            'effective_from.date_format' => 'The start date must be written as YYYY-MM-DD.',
            'effective_from.after_or_equal' => 'A new rate can start today or later, never in the past, so interest already figured never changes.',
        ];
    }
}
