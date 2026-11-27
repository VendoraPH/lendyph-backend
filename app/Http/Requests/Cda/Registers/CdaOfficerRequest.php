<?php

namespace App\Http\Requests\Cda\Registers;

use App\Http\Requests\Cda\CdaFormRequest;
use App\Models\Borrower;
use App\Models\CdaOfficer;
use App\Services\Cda\CdaAccess;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /cda/officers and PUT /cda/officers/{id} (`cda:manage_officers`).
 *
 * An officer linked to a member (`borrower_id`) takes the name and sex from
 * the member: anything sent for them is ignored and nothing is stored. An
 * unlinked officer needs both. On PUT every field is optional and the rule
 * is checked against the result.
 */
class CdaOfficerRequest extends CdaFormRequest
{
    protected function permission(): ?string
    {
        return CdaAccess::MANAGE_OFFICERS;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('put') ? 'sometimes' : 'required';

        return [
            'borrower_id' => ['nullable', 'integer', Rule::exists('borrowers', 'id')->whereNotIn('status', Borrower::NON_MEMBER_STATUSES)],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'name' => ['nullable', 'string', 'max:160'],
            'sex' => ['nullable', Rule::in(CdaOfficer::SEXES)],
            'position' => [$required, Rule::in(CdaOfficer::POSITIONS)],
            'committee' => ['nullable', 'string', 'max:80'],
            'position_title' => ['nullable', 'string', 'max:120'],
            'term_start' => [$required, 'date_format:Y-m-d'],
            'term_end' => ['nullable', 'date_format:Y-m-d'],
            'election_date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['sometimes', Rule::in(CdaOfficer::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'borrower_id.integer' => 'Link the officer to a member of the cooperative.',
            'borrower_id.exists' => 'Link the officer to a member of the cooperative.',
            'user_id.exists' => 'Choose a user of the system.',
            'user_id.integer' => 'Choose a user of the system.',
            'name.string' => 'The name must be text.',
            'name.max' => 'The name can be at most 160 characters.',
            'sex.in' => 'Choose male or female.',
            'position.required' => 'Choose the officer\'s position.',
            'position.in' => 'Choose a position from the list.',
            'committee.max' => 'The committee can be at most 80 characters.',
            'position_title.max' => 'The position title can be at most 120 characters.',
            'term_start.required' => 'Give the date the term started.',
            'term_start.date_format' => 'Give the term start as a date (YYYY-MM-DD).',
            'term_end.date_format' => 'Give the term end as a date (YYYY-MM-DD).',
            'election_date.date_format' => 'Give the election date as a date (YYYY-MM-DD).',
            'status.in' => 'Choose active, inactive, resigned or removed.',
            'remarks.max' => 'The remarks can be at most 2,000 characters.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() && $validator->errors()->hasAny(['borrower_id', 'term_start', 'term_end'])) {
                return;
            }

            $current = $this->officer();
            $linked = $this->exists('borrower_id') ? $this->input('borrower_id') !== null : $current?->borrower_id !== null;

            if (! $linked) {
                $name = $this->exists('name') ? $this->input('name') : $current?->name;
                $sex = $this->exists('sex') ? $this->input('sex') : $current?->sex;

                if (blank($name)) {
                    $validator->errors()->add('name', 'Give the officer\'s name, or link the officer to a member.');
                }

                if (blank($sex)) {
                    $validator->errors()->add('sex', 'Give the officer\'s sex, or link the officer to a member.');
                }
            }

            $start = $this->exists('term_start') ? $this->input('term_start') : $current?->term_start?->toDateString();
            $end = $this->exists('term_end') ? $this->input('term_end') : $current?->term_end?->toDateString();

            if (is_string($start) && is_string($end) && $end < $start) {
                $validator->errors()->add('term_end', 'The term can\'t end before it starts.');
            }
        });
    }

    /**
     * What to save: a linked officer stores no name or sex.
     *
     * @return array<string, mixed>
     */
    public function officerData(): array
    {
        $data = $this->validated();
        $linked = array_key_exists('borrower_id', $data) ? $data['borrower_id'] !== null : $this->officer()?->borrower_id !== null;

        if ($linked) {
            $data['name'] = null;
            $data['sex'] = null;
        }

        return $data;
    }

    private function officer(): ?CdaOfficer
    {
        $id = $this->route('officer');

        return $id === null ? null : CdaOfficer::query()->find($id);
    }
}
