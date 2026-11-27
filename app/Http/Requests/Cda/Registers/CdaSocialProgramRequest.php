<?php

namespace App\Http\Requests\Cda\Registers;

use App\Http\Requests\Cda\CdaFormRequest;
use App\Http\Resources\Cda\CdaPresenter;
use App\Models\CdaReportingPeriod;
use App\Models\CdaSocialProgram;
use App\Services\Accounting\Money;
use App\Services\Cda\CdaAccess;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /cda/periods/{year}/social-programs and PUT /cda/social-programs/{id}
 * (`cda:manage_social`). Money is pesos with at most two decimals, never
 * below ₱0.00, and the part from the CDF can't exceed what was spent. The
 * program's dates fall within its period's year. On PUT every field is
 * optional and the rules are checked against the result.
 */
class CdaSocialProgramRequest extends CdaFormRequest
{
    private const MONEY = ['budget' => 'The budget', 'actual_expense' => 'The amount spent', 'cdf_amount' => 'The amount from the CDF'];

    protected function permission(): ?string
    {
        return CdaAccess::MANAGE_SOCIAL;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('put') ? 'sometimes' : 'required';
        $money = ['numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'];

        return [
            'name' => [$required, 'string', 'max:255'],
            'category' => [$required, Rule::in(CdaSocialProgram::CATEGORIES)],
            'description' => ['nullable', 'string', 'max:5000'],
            'objective' => ['nullable', 'string', 'max:5000'],
            'held_on' => ['nullable', 'date_format:Y-m-d'],
            'ended_on' => ['nullable', 'date_format:Y-m-d'],
            'location' => ['nullable', 'string', 'max:255'],
            'target_beneficiaries' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'actual_beneficiaries' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'budget' => ['nullable', ...$money],
            'actual_expense' => ['sometimes', ...$money],
            'funding_source' => ['nullable', Rule::in(CdaSocialProgram::FUNDING_SOURCES)],
            'cdf_amount' => ['sometimes', ...$money],
            'status' => ['sometimes', Rule::in(CdaSocialProgram::STATUSES)],
            'responsible_officer_id' => ['nullable', 'integer', Rule::exists('cda_officers', 'id')->whereNull('deleted_at')],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $messages = [
            'name.required' => 'Give the program\'s name.',
            'name.max' => 'The name can be at most 255 characters.',
            'category.required' => 'Choose a category.',
            'category.in' => 'Choose a category from the list.',
            'description.max' => 'The description can be at most 5,000 characters.',
            'objective.max' => 'The objective can be at most 5,000 characters.',
            'held_on.date_format' => 'Give the date held as a date (YYYY-MM-DD).',
            'ended_on.date_format' => 'Give the end as a date (YYYY-MM-DD).',
            'location.max' => 'The location can be at most 255 characters.',
            'target_beneficiaries.integer' => 'Target beneficiaries is a whole number.',
            'target_beneficiaries.min' => 'Target beneficiaries can\'t be below 0.',
            'actual_beneficiaries.integer' => 'Beneficiaries is a whole number.',
            'actual_beneficiaries.min' => 'Beneficiaries can\'t be below 0.',
            'funding_source.in' => 'Choose a funding source from the list.',
            'status.in' => 'Choose planned, ongoing, completed or cancelled.',
            'responsible_officer_id.exists' => 'Choose an officer from the register.',
            'responsible_officer_id.integer' => 'Choose an officer from the register.',
            'remarks.max' => 'The remarks can be at most 2,000 characters.',
        ];

        foreach (self::MONEY as $field => $words) {
            $messages["{$field}.numeric"] = "{$words} is in pesos with at most two decimals.";
            $messages["{$field}.decimal"] = "{$words} is in pesos with at most two decimals.";
            $messages["{$field}.min"] = "{$words} can't be below ₱0.00.";
            $messages["{$field}.max"] = "{$words} is too large.";
        }

        return $messages;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $current = $this->program();
            $period = $current?->period ?? $this->period();

            foreach (['held_on', 'ended_on'] as $field) {
                $date = $this->exists($field) ? $this->input($field) : $current?->{$field}?->toDateString();

                if ($period !== null && is_string($date) && ! $validator->errors()->has($field)
                    && ($date < $period->period_start->toDateString() || $date > $period->period_end->toDateString())) {
                    $validator->errors()->add($field, "The program must be held within {$period->year}.");
                }
            }

            $held = $this->exists('held_on') ? $this->input('held_on') : $current?->held_on?->toDateString();
            $ended = $this->exists('ended_on') ? $this->input('ended_on') : $current?->ended_on?->toDateString();

            if (is_string($held) && is_string($ended) && $ended < $held && ! $validator->errors()->hasAny(['held_on', 'ended_on'])) {
                $validator->errors()->add('ended_on', 'The program can\'t end before it was held.');
            }

            if (! $validator->errors()->hasAny(['actual_expense', 'cdf_amount'])) {
                $spent = Money::toCentavos((string) ($this->exists('actual_expense') ? $this->input('actual_expense') : ($current->actual_expense ?? '0')));
                $cdf = Money::toCentavos((string) ($this->exists('cdf_amount') ? $this->input('cdf_amount') : ($current->cdf_amount ?? '0')));

                if ($spent !== null && $cdf !== null && $cdf > $spent) {
                    $validator->errors()->add('cdf_amount', 'The amount from the CDF can\'t be more than the amount spent.');
                }
            }
        });
    }

    /**
     * The fields to save, with money as two-decimal strings from centavos.
     *
     * @return array<string, mixed>
     */
    public function programData(): array
    {
        $data = $this->validated();

        foreach (array_keys(self::MONEY) as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $data[$field] = CdaPresenter::money((int) Money::toCentavos((string) $data[$field]));
            }
        }

        return $data;
    }

    private function program(): ?CdaSocialProgram
    {
        $id = $this->route('program');

        return $id === null ? null : CdaSocialProgram::query()->with('period')->find($id);
    }

    private function period(): ?CdaReportingPeriod
    {
        $year = $this->route('year');

        return $year === null ? null : CdaReportingPeriod::query()->where('year', (int) $year)->first();
    }
}
