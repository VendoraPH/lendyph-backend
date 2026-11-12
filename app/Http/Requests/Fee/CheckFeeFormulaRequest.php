<?php

namespace App\Http\Requests\Fee;

use App\Enums\InterestRateFrequency;
use App\Enums\TermUnit;
use App\Models\Loan;
use App\Services\Fees\FeeExpression;
use App\Services\Fees\FeeExpressionException;
use App\Services\LoanTermSchedule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * POST /fees/formula-check: a written formula and an optional sample loan.
 *
 * Only `formula_expression` is required. The sample defaults to ₱100,000.00
 * at 3 monthly, for 12 months, starting today; its maturity date is worked
 * out the way the schedule does ({@see LoanTermSchedule::maturityDate()}).
 * Open to anyone who may create or edit a fee.
 */
class CheckFeeFormulaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('fees:create') || $this->user()->can('fees:update');
    }

    public function rules(): array
    {
        return [
            'formula_expression' => ['required', 'string'],
            'principal_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'interest_rate_frequency' => ['nullable', InterestRateFrequency::rule()],
            'term' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'term_unit' => ['nullable', TermUnit::rule()],
            'min_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'max_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->has('formula_expression')) {
                return;
            }

            try {
                FeeExpression::parse((string) $this->input('formula_expression'));
            } catch (FeeExpressionException $e) {
                $v->errors()->add('formula_expression', $e->getMessage());
            }

            $min = $this->input('min_amount');
            $max = $this->input('max_amount');

            if (! $v->errors()->hasAny(['min_amount', 'max_amount']) && is_numeric($min) && is_numeric($max) && (float) $min > (float) $max) {
                $v->errors()->add('max_amount', 'The maximum must be at least the minimum.');
            }
        });
    }

    public function expression(): FeeExpression
    {
        return FeeExpression::parse((string) $this->validated('formula_expression'));
    }

    /**
     * The sample loan, unsaved, carrying what a loan stores: its principal,
     * rate and rate period, term and unit, start date and maturity date.
     */
    public function sampleLoan(): Loan
    {
        $term = (int) ($this->validated('term') ?? 12);
        $unit = (string) ($this->validated('term_unit') ?? TermUnit::Months->value);
        $start = Carbon::today();

        return (new Loan)->forceFill([
            'principal_amount' => $this->validated('principal_amount') ?? '100000.00',
            'interest_rate' => $this->validated('interest_rate') ?? '3',
            'interest_rate_frequency' => $this->validated('interest_rate_frequency') ?? InterestRateFrequency::Monthly->value,
            'term' => $term,
            'term_unit' => $unit,
            'start_date' => $start->toDateString(),
            'maturity_date' => LoanTermSchedule::maturityDate($start, $term, $unit, 'monthly')->toDateString(),
        ]);
    }
}
