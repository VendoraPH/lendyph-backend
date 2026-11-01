<?php

namespace App\Http\Requests\Loan;

use App\Enums\InterestRateFrequency;
use App\Enums\LoanFrequency;
use App\Enums\TermUnit;
use App\Services\AmortizationCalculatorService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The Amortization Calculator's inputs: a what-if loan with no product and no
 * borrower, so it is open to whoever can view loans. Every field the schedule
 * needs is required. A single request cannot ask for unbounded work: the term
 * is capped (30 years in either unit) and the schedule at
 * AmortizationCalculatorService::MAX_PERIODS instalments.
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
            // At most what the loans.scb_amount column (decimal 12,2) can hold.
            'scb_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'compare' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Refuses a term and frequency that would make more instalments than
     * AmortizationCalculatorService::MAX_PERIODS, counted as the engine
     * counts them. Only once the fields it reads are valid.
     *
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['term', 'term_unit', 'frequency', 'start_date'])) {
                    return;
                }

                $periods = AmortizationCalculatorService::periodCount(
                    $this->input('start_date'),
                    (int) $this->input('term'),
                    $this->input('term_unit'),
                    $this->input('frequency'),
                );

                if ($periods > AmortizationCalculatorService::MAX_PERIODS) {
                    $validator->errors()->add(
                        'term',
                        'This term and frequency make '.number_format($periods).' instalments; the calculator allows at most '.number_format(AmortizationCalculatorService::MAX_PERIODS).'.',
                    );
                }
            },
        ];
    }
}
