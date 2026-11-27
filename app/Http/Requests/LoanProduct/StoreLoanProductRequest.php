<?php

namespace App\Http\Requests\LoanProduct;

use App\Enums\InterestRateFrequency;
use App\Enums\LoanFrequency;
use App\Enums\TermUnit;
use App\Http\Requests\LoanProduct\Concerns\GuardsAgainstFeeCatalogOverlap;
use App\Http\Requests\LoanProduct\Concerns\RefusesTermOutsideItsRange;
use App\Rules\UniqueLoanProductName;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreLoanProductRequest extends FormRequest
{
    use GuardsAgainstFeeCatalogOverlap, RefusesTermOutsideItsRange;

    public function authorize(): bool
    {
        return $this->user()->can('loan_products:manage');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', new UniqueLoanProductName],
            'description' => ['nullable', 'string', 'max:2000'],
            'interest_rate' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'min_interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'interest_method' => ['required', 'in:straight,diminishing,upon_maturity'],
            'term' => ['required', 'integer', 'min:1'],
            'min_term' => ['nullable', 'integer', 'min:1'],
            'max_term' => ['nullable', 'integer', 'min:1'],
            'term_unit' => ['sometimes', TermUnit::rule()],
            'interest_rate_frequency' => ['sometimes', InterestRateFrequency::rule()],
            'frequency' => ['required', LoanFrequency::rule()],
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
        // Derive legacy single-value fields from range fields when not sent
        if (! $this->has('interest_rate') && $this->has('max_interest_rate')) {
            $this->merge(['interest_rate' => $this->max_interest_rate]);
        }
        if (! $this->has('term') && $this->has('max_term')) {
            $this->merge(['term' => $this->max_term]);
        }
        if (! $this->has('frequency') && $this->has('frequencies')) {
            $frequencies = $this->input('frequencies');
            if (is_array($frequencies) && count($frequencies) > 0) {
                $this->merge(['frequency' => $frequencies[0]]);
            } elseif (is_string($frequencies) && $frequencies !== '') {
                $this->merge(['frequency' => $frequencies]);
            }
        }
        if ($this->has('is_active') && ! $this->has('status')) {
            $this->merge(['status' => $this->boolean('is_active') ? 'active' : 'inactive']);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $this->guardAgainstFeeCatalogOverlap($validator);
        $this->refuseTermOutsideItsRange($validator);

        // Additional fees are retired (see UpdateLoanProductRequest): a new
        // product starts with none, so only an absent, null or empty list passes.
        $validator->after(function (Validator $v): void {
            if (! $v->errors()->has('custom_fees') && ! empty($this->input('custom_fees'))) {
                $v->errors()->add('custom_fees', UpdateLoanProductRequest::CUSTOM_FEES_RETIRED);
            }
        });
    }
}
