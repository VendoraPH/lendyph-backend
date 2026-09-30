<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\NestedRules;
use Illuminate\Validation\Rule;

/**
 * The `deductions.*.amount` constraint shared by every write that sets a loan's
 * deductions: create, restructure and edit.
 *
 * A `percentage` deduction's amount is a RATE, prefilled from the product's
 * processing / service / notarial fee, so it takes the four decimal places
 * those DECIMAL(8,4) columns store and no more — a longer one is a 422, never
 * a rate silently cut to fit. A `fixed` deduction is a peso figure and keeps
 * the rule it always had.
 */
trait ValidatesDeductionAmounts
{
    protected function deductionAmountRule(): NestedRules
    {
        return Rule::forEach(fn (mixed $value, string $attribute, ?array $data, mixed $deduction): array => [
            'required_with:deductions',
            'numeric',
            'min:0',
            ...(is_array($deduction) && ($deduction['type'] ?? null) === 'percentage' ? ['decimal:0,4'] : []),
        ]);
    }
}
