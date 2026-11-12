<?php

namespace App\Http\Requests\Fee\Concerns;

use App\Models\Fee;
use App\Services\FeeAmount;
use App\Services\Fees\FeeExpression;
use App\Services\Fees\FeeExpressionException;
use Illuminate\Contracts\Validation\Validator;

/**
 * The fields of fee type "formula" (decision 5e) on a catalog fee: `value`
 * is the rate (a percentage of the principal, at most 100, as the frontend
 * caps it), `per_month` multiplies it by the term in months, and
 * `min_amount` / `max_amount` are optional peso bounds, to the centavo, with
 * the minimum no larger than the maximum. See {@see FeeAmount}.
 *
 * A formula may instead be written out (`formula_expression`, Phase 4): it is
 * checked by {@see FeeExpression::parse()} and refused in words on
 * `formula_expression`; once set, `value` is stored as 0 and `per_month` as
 * false, and the bounds still apply. Any other type clears it.
 *
 * Shared by `StoreFeeRequest` and `UpdateFeeRequest`. A loan product's own
 * custom fees are retired and no longer validated here (see
 * `UpdateLoanProductRequest::CUSTOM_FEES_RETIRED`).
 */
trait ValidatesFormulaFields
{
    private const RATE_CAP_MESSAGE = 'A formula fee\'s rate cannot exceed 100% of the loan amount.';

    private const RATE_NEEDED_MESSAGE = 'Enter the rate when a formula fee goes back to a rate of the principal.';

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
            "{$prefix}formula_expression" => ['nullable', 'string'],
        ];
    }

    /**
     * Whether this request writes a formula out: a `formula` fee (sent, or
     * stored when not sent) with a non-empty `formula_expression`. Such a fee
     * needs no rate.
     */
    protected function writesFormula(?Fee $existing = null): bool
    {
        $type = $this->has('type') ? $this->input('type') : $existing?->type;
        $expression = $this->has('formula_expression') ? $this->input('formula_expression') : $existing?->formula_expression;

        return $type === 'formula' && is_string($expression) && trim($expression) !== '';
    }

    /**
     * A catalog fee's minimum may not exceed its maximum, and a formula's rate
     * may not exceed 100%. On an update a field that isn't resent is the stored
     * one, so lowering only the maximum below the stored minimum, or switching
     * a ₱500 fixed fee to formula without a new rate, is caught too.
     */
    protected function guardFormulaBounds(Validator $validator, ?Fee $existing = null): void
    {
        $validator->after(function (Validator $v) use ($existing): void {
            $type = $this->has('type') ? $this->input('type') : $existing?->type;
            $rate = $this->has('value') ? $this->input('value') : $existing?->value;
            $writesFormula = $this->writesFormula($existing);

            if ($type === 'formula' && ! $writesFormula && ! $v->errors()->has('value') && $this->rateIsAboveCap($rate)) {
                $v->errors()->add('value', self::RATE_CAP_MESSAGE);
            }

            if ($writesFormula && $this->has('formula_expression') && ! $v->errors()->has('formula_expression')) {
                try {
                    FeeExpression::parse((string) $this->input('formula_expression'));
                } catch (FeeExpressionException $e) {
                    $v->errors()->add('formula_expression', $e->getMessage());
                }
            }

            // A written formula stored its rate as 0: going back to the
            // recipe needs a rate, or the fee would charge nothing.
            if ($type === 'formula' && ! $writesFormula && filled($existing?->formula_expression) && ! $this->has('value')) {
                $v->errors()->add('value', self::RATE_NEEDED_MESSAGE);
            }

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
     * The validated data, with the formula fields cleared on a fee whose type
     * (sent, or stored when not sent) isn't formula, so a fee switched to fixed
     * or percentage keeps no bounds or written formula it no longer uses. A
     * formula fee with a written formula (sent, or stored when not sent) is
     * stored trimmed, with `value` 0 and `per_month` false; an empty one is
     * stored as null, the recipe.
     *
     * @return array<string, mixed>
     */
    protected function withFormulaFieldsNormalised(array $validated, ?Fee $existing = null): array
    {
        $type = $validated['type'] ?? $existing?->type;
        $sentExpression = array_key_exists('formula_expression', $validated);
        $hadExpression = filled($existing?->formula_expression);

        if ($type === 'formula') {
            $expression = $sentExpression ? $validated['formula_expression'] : $existing?->formula_expression;
            $expression = is_string($expression) ? trim($expression) : '';

            if ($expression !== '') {
                return array_merge($validated, ['formula_expression' => $expression, 'value' => 0, 'per_month' => false]);
            }

            return $sentExpression || $hadExpression ? array_merge($validated, ['formula_expression' => null]) : $validated;
        }

        $hadFormulaFields = $existing !== null && ($existing->per_month || $existing->min_amount !== null || $existing->max_amount !== null || $hadExpression);

        if (! $hadFormulaFields && ! array_intersect_key($validated, array_flip(['per_month', 'min_amount', 'max_amount', 'formula_expression']))) {
            return $validated;
        }

        return array_merge($validated, ['per_month' => false, 'min_amount' => null, 'max_amount' => null, 'formula_expression' => null]);
    }

    private function rateIsAboveCap(mixed $rate): bool
    {
        return is_numeric($rate) && (float) $rate > 100;
    }

    private function boundsAreInverted(mixed $min, mixed $max): bool
    {
        return is_numeric($min) && is_numeric($max) && (float) $min > (float) $max;
    }
}
