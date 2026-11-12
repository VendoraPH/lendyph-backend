<?php

namespace App\Http\Requests\Loan;

use App\Services\Binhs\BinhsCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The Amortization (BINHS) page's inputs. A what-if schedule open to whoever
 * can view loans, with every input capped so one request can't ask for
 * unbounded work: a term of at most 60 months and at most 60 paid flags.
 *
 * `loan_id` alone asks only for that loan's prefill; with it, the inputs are
 * optional, but sending any of them asks for the schedules too, and then
 * every required one must be there.
 */
class BinhsCalculatorRequest extends FormRequest
{
    /** @var list<string> */
    private const INPUTS = ['principal', 'annual_rate', 'term_months', 'start_date', 'scbu_per_period', 'paid_flags'];

    public function authorize(): bool
    {
        return $this->user()->can('loans:view');
    }

    public function rules(): array
    {
        $required = Rule::requiredIf(fn (): bool => $this->wantsSchedules());

        return [
            'loan_id' => ['nullable', 'integer', 'min:1'],
            'principal' => [$required, 'nullable', 'numeric', 'min:1', 'max:100000000', 'decimal:0,2'],
            'annual_rate' => [$required, 'nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'term_months' => [$required, 'nullable', 'integer', 'min:1', 'max:'.BinhsCalculator::MAX_TERM_MONTHS],
            'start_date' => [$required, 'nullable', 'date_format:Y-m-d'],
            // At most what a loan's build-up (decimal 12,2 on products) is allowed.
            'scbu_per_period' => ['nullable', 'numeric', 'min:0', 'max:9999999.99', 'decimal:0,2'],
            'paid_flags' => ['nullable', 'array', 'max:'.BinhsCalculator::MAX_TERM_MONTHS],
            'paid_flags.*' => ['boolean'],
        ];
    }

    /**
     * Refuses more paid flags than the term has periods.
     *
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['term_months', 'paid_flags'])) {
                    return;
                }

                $flags = $this->input('paid_flags');

                if (is_array($flags) && count($flags) > (int) $this->input('term_months')) {
                    $validator->errors()->add('paid_flags', 'There are more paid flags than the term has months.');
                }
            },
        ];
    }

    public function wantsSchedules(): bool
    {
        return ! $this->filled('loan_id') || collect(self::INPUTS)->contains(fn (string $input) => $this->has($input));
    }

    /**
     * The validated inputs, money and rates as exact decimal strings.
     *
     * @return array{principal: string, annual_rate: string, term_months: int, scbu_per_period: string, start_date: CarbonImmutable, paid_flags: list<bool>}
     */
    public function scheduleInputs(): array
    {
        $validated = $this->validated();

        return [
            'principal' => (string) $validated['principal'],
            'annual_rate' => (string) $validated['annual_rate'],
            'term_months' => (int) $validated['term_months'],
            'scbu_per_period' => (string) ($validated['scbu_per_period'] ?? '0'),
            'start_date' => CarbonImmutable::createFromFormat('!Y-m-d', $validated['start_date']),
            'paid_flags' => array_values(array_map(
                fn ($flag): bool => filter_var($flag, FILTER_VALIDATE_BOOLEAN),
                $validated['paid_flags'] ?? [],
            )),
        ];
    }
}
