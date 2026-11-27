<?php

namespace App\Http\Requests\Cda\Registers;

use App\Http\Requests\Cda\CdaFormRequest;
use App\Models\CdaMediationCase;
use App\Models\CdaReportingPeriod;
use App\Services\Cda\CdaAccess;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /cda/periods/{year}/mediation-cases and PUT /cda/mediation-cases/{id}
 * (`cda:manage_mediation`). The case number is given by the server.
 *
 * - The filing date is never in the future.
 * - A resolved, escalated or withdrawn case needs the date it closed
 *   (`resolved_on`), on or after the filing; a pending case or one in
 *   mediation has none. That date decides which halves the case belongs to.
 * - A new case must fall in the year it is recorded under.
 *
 * No message repeats anything typed into the case.
 */
class CdaMediationCaseRequest extends CdaFormRequest
{
    private const CLOSED = ['resolved', 'escalated', 'withdrawn'];

    protected function permission(): ?string
    {
        return CdaAccess::MANAGE_MEDIATION;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('put') ? 'sometimes' : 'required';

        return [
            'filed_on' => [$required, 'date_format:Y-m-d'],
            'complainant' => ['nullable', 'string', 'max:2000'],
            'respondent' => ['nullable', 'string', 'max:2000'],
            'nature' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'mediator' => ['nullable', 'string', 'max:160'],
            'mediation_started_on' => ['nullable', 'date_format:Y-m-d'],
            'meetings_count' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'resolution' => ['nullable', 'string', 'max:10000'],
            'resolved_on' => ['nullable', 'date_format:Y-m-d'],
            'status' => [$required, Rule::in(CdaMediationCase::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'filed_on.required' => 'Give the date the case was filed.',
            'filed_on.date_format' => 'Give the filing date as a date (YYYY-MM-DD).',
            'complainant.max' => 'The complainant can be at most 2,000 characters.',
            'respondent.max' => 'The respondent can be at most 2,000 characters.',
            'nature.max' => 'The nature of the dispute can be at most 255 characters.',
            'description.max' => 'The description can be at most 10,000 characters.',
            'mediator.max' => 'The mediator can be at most 160 characters.',
            'mediation_started_on.date_format' => 'Give the start of mediation as a date (YYYY-MM-DD).',
            'meetings_count.integer' => 'Meetings is a whole number.',
            'meetings_count.min' => 'Meetings can\'t be below 0.',
            'meetings_count.max' => 'Meetings can be at most 1,000.',
            'resolution.max' => 'The resolution can be at most 10,000 characters.',
            'resolved_on.date_format' => 'Give the closing date as a date (YYYY-MM-DD).',
            'status.required' => 'Choose the case\'s status.',
            'status.in' => 'Choose pending, in mediation, resolved, escalated or withdrawn.',
            'remarks.max' => 'The remarks can be at most 2,000 characters.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $errors = $validator->errors();
            $current = $this->mediationCase();
            $date = fn (string $field) => $this->exists($field) ? $this->input($field) : $current?->{$field}?->toDateString();
            $filed = $date('filed_on');
            $started = $date('mediation_started_on');
            $closed = $date('resolved_on');
            $status = $this->exists('status') ? $this->input('status') : $current?->status;
            $today = now('Asia/Manila')->toDateString();

            if (is_string($filed) && ! $errors->has('filed_on') && $filed > $today) {
                $errors->add('filed_on', 'The filing date can\'t be in the future.');
            }

            if (in_array($status, self::CLOSED, true) && blank($closed) && ! $errors->has('resolved_on')) {
                $errors->add('resolved_on', 'Give the date the case was resolved, escalated or withdrawn.');
            } elseif (! in_array($status, self::CLOSED, true) && filled($closed) && ! $errors->has('resolved_on')) {
                $errors->add('resolved_on', 'A pending case or one still in mediation has no closing date.');
            }

            if (is_string($filed) && is_string($closed) && $closed < $filed && ! $errors->hasAny(['filed_on', 'resolved_on'])) {
                $errors->add('resolved_on', 'A case can\'t close before it was filed.');
            }

            if (is_string($closed) && $closed > $today && ! $errors->has('resolved_on')) {
                $errors->add('resolved_on', 'The closing date can\'t be in the future.');
            }

            if (is_string($filed) && is_string($started) && $started < $filed && ! $errors->hasAny(['filed_on', 'mediation_started_on'])) {
                $errors->add('mediation_started_on', 'Mediation can\'t start before the case was filed.');
            }

            $period = $this->period();

            if ($current === null && $period !== null && is_string($filed) && ! $errors->has('filed_on')) {
                if ($filed > $period->period_end->toDateString()) {
                    $errors->add('filed_on', "This case was filed after {$period->year}, so it doesn't belong to the {$period->year} register.");
                } elseif (is_string($closed) && $closed < $period->period_start->toDateString()) {
                    $errors->add('filed_on', "This case was closed before {$period->year}, so it doesn't belong to the {$period->year} register.");
                }
            }
        });
    }

    private function mediationCase(): ?CdaMediationCase
    {
        $id = $this->route('case');

        return $id === null ? null : CdaMediationCase::query()->find($id);
    }

    private function period(): ?CdaReportingPeriod
    {
        $year = $this->route('year');

        return $year === null ? null : CdaReportingPeriod::query()->where('year', (int) $year)->first();
    }
}
