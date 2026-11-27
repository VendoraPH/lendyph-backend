<?php

namespace App\Http\Requests\Cda;

use App\Services\Cda\CdaAccess;

/**
 * PATCH /cda/reports/{report}/deadline {override_on, reference}: a deadline
 * for this report alone, with its reference; null removes it.
 */
class UpdateCdaReportDeadlineRequest extends CdaFormRequest
{
    protected function permission(): ?string
    {
        return CdaAccess::SETTINGS;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'override_on' => ['present', 'nullable', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:120', 'required_with:override_on'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['reference.required_with' => 'Give the reference for this report\'s own deadline.'];
    }
}
