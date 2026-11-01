<?php

namespace App\Http\Requests\Loan;

use App\Enums\InterestRateFrequency;
use App\Enums\LoanFrequency;
use App\Enums\TermUnit;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Amortization Calculator's inputs: a what-if loan with no product and no
 * borrower, so it is open to whoever can view loans. Every field the schedule
 * needs is required; the term is capped (30 years in either unit) so a single
 * request cannot ask for unbounded work.
 */
class AmortizationCalculatorRequest extends FormRequest
{
    public const INTEREST_METHODS = ['straight', 'diminishing', 'upon_maturity'];

    public function authorize(): bool
    {
        return $this->user()->can('loans:view');
    }

    public function rules(): array
    {
        return [
            'principal_amount' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            // As StoreLoanRequest takes a rate: decimals allowed, never rounded.
            'interest_rate' => ['required', 'numeric', 'gt:0', 'max:100', 'decimal:0,4'],
            'interest_rate_frequency' => ['required', InterestRateFrequency::rule()],
            'interest_method' => ['required', 'in:'.implode(',', self::INTEREST_METHODS)],
            'term' => ['required', 'integer', 'min:1', $this->input('term_unit') === TermUnit::Days->value ? 'max:10950' : 'max:360'],
            'term_unit' => ['required', TermUnit::rule()],
            'frequency' => ['required', LoanFrequency::rule()],
            'start_date' => ['required', 'date'],
            'scb_amount' => ['nullable', 'numeric', 'min:0'],
            'compare' => ['nullable', 'boolean'],
        ];
    }
}
