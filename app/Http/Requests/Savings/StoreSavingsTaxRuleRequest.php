<?php

namespace App\Http\Requests\Savings;

use App\Models\SavingsTaxRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /savings/tax-rules` (savings:settings and branches:view_all): a new withholding tax rate
 * for a holder type, from today or later. Rules are only ever appended.
 */
class StoreSavingsTaxRuleRequest extends FormRequest
{
    use AuthorizesCompanyWideSavings;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'holder_type' => ['required', Rule::in(SavingsTaxRule::HOLDER_TYPES)],
            'rate' => ['required', ...SavingsRules::rate('tax rate')],
            'effective_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now()->toDateString()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...SavingsRules::messages([], ['rate' => 'tax rate']),
            'holder_type.required' => 'Choose whom the rate applies to: members or walk-ins.',
            'holder_type.in' => 'A tax rule applies to members or walk-ins.',
            'rate.required' => 'Enter the tax rate.',
            'effective_from.required' => 'Enter the date the rate starts.',
            'effective_from.date_format' => 'The start date must be written as YYYY-MM-DD.',
            'effective_from.after_or_equal' => 'A new rate can start today or later, never in the past, so interest already figured never changes.',
        ];
    }
}
