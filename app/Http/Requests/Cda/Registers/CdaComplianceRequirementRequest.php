<?php

namespace App\Http\Requests\Cda\Registers;

use App\Http\Requests\Cda\CdaFormRequest;
use App\Models\CdaComplianceRequirement;
use App\Services\Cda\CdaAccess;
use DateTimeInterface;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /cda/periods/{year}/requirements and PUT /cda/requirements/{id}
 * (`cda:prepare` with `branches:view_all`). An extension can't fall before
 * the default due date. A filed item needs its filing date, which can't be
 * in the future, and only a filed item has one (moving an item away from
 * filed clears it). On PUT every field is
 * optional and the rules are checked against the result.
 */
class CdaComplianceRequirementRequest extends CdaFormRequest
{
    protected function permission(): ?string
    {
        return CdaAccess::PREPARE;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('put') ? 'sometimes' : 'required';

        return [
            'title' => [$required, 'string', 'max:120', 'regex:/\S/'],
            'description' => ['nullable', 'string', 'max:2000'],
            'default_due_on' => [$required, 'date_format:Y-m-d'],
            'extended_due_on' => ['nullable', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(CdaComplianceRequirement::STATUSES)],
            'filed_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.required' => 'Give the requirement a title.',
            'title.regex' => 'Give the requirement a title.',
            'title.max' => 'The title takes up to 120 characters.',
            'description.max' => 'The description takes up to 2,000 characters.',
            'default_due_on.required' => 'Give the due date.',
            'default_due_on.date_format' => 'Give the due date as a date (YYYY-MM-DD).',
            'extended_due_on.date_format' => 'Give the extended due date as a date (YYYY-MM-DD).',
            'reference.max' => 'The reference takes up to 120 characters.',
            'status.in' => 'Choose pending, filed or not applicable.',
            'filed_on.date_format' => 'Give the filing date as a date (YYYY-MM-DD).',
            'filed_on.before_or_equal' => 'The filing date can\'t be in the future.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $current = $this->requirement();
            $value = fn (string $field) => $this->exists($field) ? $this->input($field) : $current?->{$field};
            $date = fn (string $field) => ($found = $value($field)) instanceof DateTimeInterface ? $found->format('Y-m-d') : $found;

            $default = $date('default_due_on');
            $extended = $date('extended_due_on');

            if (is_string($default) && is_string($extended) && $extended < $default && ! $validator->errors()->hasAny(['default_due_on', 'extended_due_on'])) {
                $validator->errors()->add('extended_due_on', 'The extended due date can\'t be before the due date.');
            }

            $status = $value('status') ?? CdaComplianceRequirement::STATUS_PENDING;
            $filed = $date('filed_on');

            if ($validator->errors()->hasAny(['status', 'filed_on'])) {
                return;
            }

            if ($status === CdaComplianceRequirement::STATUS_FILED && ! is_string($filed)) {
                $validator->errors()->add('filed_on', 'Give the date it was filed.');
            }

            if ($status !== CdaComplianceRequirement::STATUS_FILED && $this->filled('filed_on')) {
                $validator->errors()->add('filed_on', 'Only a filed requirement has a filing date.');
            }
        });
    }

    /** The item a PUT changes, or null on POST. */
    private function requirement(): ?CdaComplianceRequirement
    {
        $id = $this->route('requirement');

        return $id === null ? null : CdaComplianceRequirement::query()->find((int) $id);
    }
}
