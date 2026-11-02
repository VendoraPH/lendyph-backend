<?php

namespace App\Http\Requests\Fee\Concerns;

use App\Models\Fee;
use App\Services\FeeAmount;
use Illuminate\Contracts\Validation\Validator;

/**
 * The fields of fee type "formula" (decision 5e), on a catalog fee and on a
 * product's custom fees: `value` is the rate (a percentage of the principal,
 * as for a percentage fee), `per_month` multiplies it by the term in months,
 * and `min_amount` / `max_amount` are optional peso bounds, to the centavo,
 * with the minimum no larger than the maximum. See {@see FeeAmount}.
 *
 * Shared by `StoreFeeRequest`, `UpdateFeeRequest` and the two loan product
 * requests.
 */
trait ValidatesFormulaFields
{
    protected function feeTypeRule(): string
    {
        return 'in:'.implode(',', FeeAmount::TYPES);
    }

    /**
     * @return array<string, list<string>>
     */
    protected function formulaFieldRules(string $prefix = ''): array
    {
        return [
            "{$prefix}per_month" => ['sometimes', 'boolean'],
            "{$prefix}min_amount" => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            "{$prefix}max_amount" => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
        ];
    }

    /**
     * A catalog fee's minimum may not exceed its maximum. On an update a bound
     * that isn't resent is the stored one, so lowering only the maximum below
     * the stored minimum is caught too.
     */
    protected function guardFormulaBounds(Validator $validator, ?Fee $existing = null): void
    {
        $validator->after(function (Validator $v) use ($existing): void {
            if ($v->errors()->hasAny(['min_amount', 'max_amount'])) {
                return;
            }

            $min = $this->has('min_amount') ? $this->input('min_amount') : $existing?->min_amount;
            $max = $this->has('max_amount') ? $this->input('max_amount') : $existing?->max_amount;

            if ($this->boundsAreInverted($min, $max)) {
                $v->errors()->add('max_amount', 'The maximum must be at least the minimum.');
            }
        });
    }

    /**
     * The same rule for each of a product's custom fees.
     */
    protected function guardCustomFeeFormulaBounds(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $fees = $this->input('custom_fees');

            if (! is_array($fees)) {
                return;
            }

            foreach ($fees as $index => $fee) {
                if (! is_array($fee) || $v->errors()->hasAny(["custom_fees.{$index}.min_amount", "custom_fees.{$index}.max_amount"])) {
                    continue;
                }

                if ($this->boundsAreInverted($fee['min_amount'] ?? null, $fee['max_amount'] ?? null)) {
                    $v->errors()->add("custom_fees.{$index}.max_amount", 'The maximum must be at least the minimum.');
                }
            }
        });
    }

    /**
     * The validated data, with the formula fields cleared on a fee whose type
     * (sent, or stored when not sent) isn't formula, so a fee switched to fixed
     * or percentage keeps no bounds it no longer uses.
     *
     * @return array<string, mixed>
     */
    protected function withFormulaFieldsNormalised(array $validated, ?Fee $existing = null): array
    {
        $type = $validated['type'] ?? $existing?->type;

        if ($type === 'formula') {
            return $validated;
        }

        $hadFormulaFields = $existing !== null && ($existing->per_month || $existing->min_amount !== null || $existing->max_amount !== null);

        if (! $hadFormulaFields && ! array_intersect_key($validated, array_flip(['per_month', 'min_amount', 'max_amount']))) {
            return $validated;
        }

        return array_merge($validated, ['per_month' => false, 'min_amount' => null, 'max_amount' => null]);
    }

    private function boundsAreInverted(mixed $min, mixed $max): bool
    {
        return is_numeric($min) && is_numeric($max) && (float) $min > (float) $max;
    }
}
