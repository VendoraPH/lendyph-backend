<?php

namespace App\Http\Requests\LoanProduct;

use App\Enums\InterestRateFrequency;
use App\Enums\LoanFrequency;
use App\Enums\TermUnit;
use App\Http\Requests\LoanProduct\Concerns\GuardsAgainstFeeCatalogOverlap;
use App\Http\Requests\LoanProduct\Concerns\RefusesTermOutsideItsRange;
use App\Models\LoanProduct;
use App\Rules\UniqueLoanProductName;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLoanProductRequest extends FormRequest
{
    use GuardsAgainstFeeCatalogOverlap, RefusesTermOutsideItsRange;

    /**
     * A product's `custom_fees` ("Additional fees") are retired. No release,
     * preview, disclosure or journal ever charged them; fees are set up in
     * Settings → Fees, scoped to the product. The stored list stays readable
     * on the product, and `loan-products:retired-custom-fees-report` lists it.
     */
    public const CUSTOM_FEES_RETIRED = 'Additional fees are retired. Set fees up in Settings → Fees, scoped to this product.';

    public function authorize(): bool
    {
        return $this->user()->can('loan_products:manage');
    }

    public function rules(): array
    {
        $product = $this->route('loan_product');

        return [
            // Checked only when the normalised name changes, so an existing duplicate still saves.
            'name' => ['sometimes', 'string', 'max:255', new UniqueLoanProductName(
                $product instanceof LoanProduct ? $product->id : null,
                $product instanceof LoanProduct ? $product->name : null,
            )],
            'description' => ['nullable', 'string', 'max:2000'],
            'interest_rate' => ['sometimes', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'min_interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'interest_method' => ['sometimes', 'in:straight,diminishing,upon_maturity'],
            'term' => ['sometimes', 'integer', 'min:1'],
            'min_term' => ['nullable', 'integer', 'min:1'],
            'max_term' => ['nullable', 'integer', 'min:1'],
            'term_unit' => ['sometimes', TermUnit::rule()],
            'interest_rate_frequency' => ['sometimes', InterestRateFrequency::rule()],
            'frequency' => ['sometimes', LoanFrequency::rule()],
            'frequencies' => ['nullable', 'array'],
            'frequencies.*' => [LoanFrequency::rule()],
            'processing_fee' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'min_processing_fee' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'max_processing_fee' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'service_fee' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'min_service_fee' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'max_service_fee' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'notarial_fee' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'custom_fees' => ['nullable', 'array'],
            'penalty_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'grace_period_days' => ['nullable', 'integer', 'min:0'],
            'scb_required' => ['nullable', 'boolean'],
            'min_scb' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'max_scb' => ['nullable', 'numeric', 'min:0', 'max:9999999.99', 'gte:min_scb'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', 'in:active,inactive'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('interest_rate') && $this->has('max_interest_rate')) {
            $this->merge(['interest_rate' => $this->max_interest_rate]);
        }
        // `term` is the product's default term, not the top of the range. An edit
        // that doesn't send one keeps the stored value, so it isn't copied from
        // `max_term` here.
        // `frequency` is the product's default, one of `frequencies`. Saving a
        // new list keeps it unless it's no longer on the list.
        if (! $this->has('frequency') && $this->has('frequencies')) {
            $frequencies = $this->input('frequencies');
            $stored = $this->route('loan_product')?->getRawOriginal('frequency');
            if (is_array($frequencies) && count($frequencies) > 0 && ! in_array($stored, $frequencies, true)) {
                $this->merge(['frequency' => $frequencies[0]]);
            } elseif (is_string($frequencies) && $frequencies !== '' && $frequencies !== $stored) {
                $this->merge(['frequency' => $frequencies]);
            }
        }
        if ($this->has('is_active') && ! $this->has('status')) {
            $this->merge(['status' => $this->boolean('is_active') ? 'active' : 'inactive']);
        }
    }

    public function withValidator(Validator $validator): void
    {
        // The apiResource route for `loan-products` binds `{loan_product}`
        // (ResourceRegistrar singularizes and underscores the resource
        // name), so the resolved model lives under that snake_case key —
        // `$this->loanProduct` would silently miss it and fall back to null.
        $product = $this->route('loan_product');

        $this->guardAgainstFeeCatalogOverlap($validator, $product instanceof LoanProduct ? $product : null);
        $this->refuseChangedCustomFees($validator, $product instanceof LoanProduct ? $product : null);
        $this->refuseTermOutsideItsRange($validator, $product instanceof LoanProduct ? $product : null);
    }

    /**
     * The validated data, without a non-empty `custom_fees`: the refusal above
     * has proved it equals the stored list, so it is never rewritten. An empty
     * list (or null) is kept, and clears the stored one.
     *
     * @param  string|null  $key
     * @param  mixed  $default
     */
    public function validated($key = null, $default = null): mixed
    {
        $validated = parent::validated();

        if (! empty($validated['custom_fees'])) {
            unset($validated['custom_fees']);
        }

        return data_get($validated, $key, $default);
    }

    /**
     * Refuse a `custom_fees` that differs from the stored list. Absent, null,
     * `[]`, or the stored list sent back (keys in any order, numbers as
     * numbers or numeric strings, null fields left out) is accepted.
     */
    private function refuseChangedCustomFees(Validator $validator, ?LoanProduct $product): void
    {
        $validator->after(function (Validator $v) use ($product): void {
            $sent = $this->input('custom_fees');

            if ($v->errors()->has('custom_fees') || empty($sent)) {
                return;
            }

            if (self::canonicalFees($sent) !== self::canonicalFees($product?->custom_fees ?? [])) {
                $v->errors()->add('custom_fees', self::CUSTOM_FEES_RETIRED);
            }
        });
    }

    /**
     * A custom fee list in one comparable form: object keys sorted, null
     * fields dropped, numbers and numeric strings as floats.
     */
    private static function canonicalFees(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                $value = array_filter($value, static fn (mixed $item): bool => $item !== null);
                ksort($value);
            }

            return array_map(self::canonicalFees(...), $value);
        }

        return is_numeric($value) ? (float) $value : $value;
    }
}
