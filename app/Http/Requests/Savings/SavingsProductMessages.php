<?php

namespace App\Http\Requests\Savings;

/**
 * The messages the savings product requests share, in words.
 */
final class SavingsProductMessages
{
    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            ...SavingsRules::messages([
                'minimum_opening_balance' => 'minimum opening balance',
                'maintaining_balance' => 'maintaining balance',
                'minimum_adb_for_interest' => 'minimum average daily balance for interest',
            ], ['annual_interest_rate' => 'interest rate']),
            'code.required' => 'Enter a product code.',
            'code.max' => 'The product code can be at most 32 characters.',
            'code.regex' => 'The product code can hold only letters, numbers and dashes.',
            'code.unique' => 'Another savings product already uses this code.',
            'name.required' => 'Enter the product name.',
            'name.max' => 'The product name can be at most 120 characters.',
            'kind.required' => 'Choose whether this is a regular savings or a time deposit product.',
            'kind.in' => 'A savings product is regular savings or a time deposit.',
            'interest_crediting.in' => 'Interest is credited monthly, quarterly, semi-annually or annually.',
            'term_options_days.required_if' => 'A time deposit product needs at least one term, in days.',
            'term_options_days.prohibited_unless' => 'Only a time deposit product offers terms.',
            'term_options_days.array' => 'List the terms offered, in days.',
            'term_options_days.min' => 'A time deposit product needs at least one term, in days.',
            'term_options_days.max' => 'A product can offer at most 20 terms.',
            'term_options_days.*.integer' => 'Each term is a whole number of days.',
            'term_options_days.*.between' => 'Each term is between 1 and 3,650 days.',
            'term_options_days.*.distinct' => 'Each term can be listed once.',
            'pretermination_product_id.prohibited_unless' => 'Only a time deposit product has an early termination rate.',
            'pretermination_product_id.integer' => 'Choose the regular savings product whose rate pays early terminations.',
            'pretermination_product_id.exists' => 'Early terminations are paid at a regular savings product\'s rate; choose one.',
            'annual_interest_rate.required' => 'Enter the product\'s interest rate.',
            'annual_interest_rate.prohibited' => 'A rate is never edited. Add a new rate from a date under the product\'s rates instead.',
            'is_active.boolean' => 'Say whether the product is on or off.',
        ];
    }
}
