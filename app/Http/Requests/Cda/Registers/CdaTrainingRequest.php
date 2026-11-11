<?php

namespace App\Http\Requests\Cda\Registers;

use App\Http\Requests\Cda\CdaFormRequest;
use App\Models\CdaOfficerTraining;
use App\Services\Cda\CdaAccess;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /cda/trainings and PUT /cda/trainings/{id} (`cda:manage_officers`),
 * multipart with an optional `certificate` (a PUT that carries one is sent as
 * POST with `_method=PUT`). A completed training needs the date it was
 * finished; hours have at most two decimals. On PUT every field is optional
 * and the rules are checked against the result.
 */
class CdaTrainingRequest extends CdaFormRequest
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
            'officer_id' => [$required, 'integer', Rule::exists('cda_officers', 'id')->whereNull('deleted_at')],
            'title' => [$required, 'string', 'max:255'],
            'requirement_key' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9_]+$/'],
            'training_type' => ['nullable', 'string', 'max:40'],
            'is_mandatory' => ['sometimes', 'boolean'],
            'provider' => ['nullable', 'string', 'max:160'],
            'provider_accreditation_no' => ['nullable', 'string', 'max:80'],
            'started_on' => ['nullable', 'date_format:Y-m-d'],
            'completed_on' => ['nullable', 'date_format:Y-m-d'],
            'hours' => ['sometimes', 'numeric', 'min:0', 'max:9999.99', 'decimal:0,2'],
            'certificate_number' => ['nullable', 'string', 'max:80'],
            'status' => ['sometimes', Rule::in(CdaOfficerTraining::STATUSES)],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'certificate' => CdaUploadRules::file(required: false),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'officer_id.required' => 'Choose an officer from the register.',
            'officer_id.integer' => 'Choose an officer from the register.',
            'officer_id.exists' => 'Choose an officer from the register.',
            'title.required' => 'Give the training\'s title.',
            'title.string' => 'Give the training\'s title.',
            'title.max' => 'The title can be at most 255 characters.',
            'requirement_key.regex' => 'A requirement key is lower-case letters, digits and underscores, as in CDA settings.',
            'requirement_key.max' => 'A requirement key can be at most 80 characters.',
            'training_type.max' => 'The training type can be at most 40 characters.',
            'is_mandatory.boolean' => 'Say whether the training is mandatory (yes or no).',
            'provider.max' => 'The provider can be at most 160 characters.',
            'provider_accreditation_no.max' => 'The accreditation number can be at most 80 characters.',
            'started_on.date_format' => 'Give the start as a date (YYYY-MM-DD).',
            'completed_on.date_format' => 'Give the completion as a date (YYYY-MM-DD).',
            'hours.numeric' => 'Hours must be a number.',
            'hours.min' => 'Hours can\'t be below 0.',
            'hours.max' => 'Hours can be at most 9,999.99.',
            'hours.decimal' => 'Hours can have at most two decimals.',
            'certificate_number.max' => 'The certificate number can be at most 80 characters.',
            'status.in' => 'Choose completed, incomplete, scheduled, expired or not required.',
            'expires_on.date_format' => 'Give the expiry as a date (YYYY-MM-DD).',
            'remarks.max' => 'The remarks can be at most 2,000 characters.',
            ...CdaUploadRules::messages('certificate'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $current = $this->training();
            $value = fn (string $field) => $this->exists($field) ? $this->input($field) : $current?->{$field}?->toDateString();
            $status = $this->exists('status') ? $this->input('status') : ($current->status ?? 'completed');
            $started = $value('started_on');
            $completed = $value('completed_on');

            if ($status === 'completed' && blank($completed) && ! $validator->errors()->has('completed_on')) {
                $validator->errors()->add('completed_on', 'Give the date a completed training was finished.');
            }

            if (is_string($started) && is_string($completed) && $completed < $started && ! $validator->errors()->hasAny(['started_on', 'completed_on'])) {
                $validator->errors()->add('completed_on', 'A training can\'t be completed before it starts.');
            }
        });
    }

    /** @return array<string, mixed> the training's fields, without the file */
    public function trainingData(): array
    {
        return collect($this->validated())->except('certificate')->all();
    }

    private function training(): ?CdaOfficerTraining
    {
        $id = $this->route('training');

        return $id === null ? null : CdaOfficerTraining::query()->find($id);
    }
}
