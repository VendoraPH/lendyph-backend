<?php

namespace App\Http\Requests\LoanOrigination\Staff;

use App\Models\OriginationApplication;
use Illuminate\Validation\Rule;

/**
 * `POST /loan-origination/applications/{id}/transition`: the target status,
 * an internal note, an optional message for the applicant (logged, never
 * sent) and the version the client read.
 */
class TransitionApplicationRequest extends StaffWriteRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'to' => ['required', 'string', Rule::in(OriginationApplication::STATUSES)],
            'note' => ['nullable', 'string', 'max:2000'],
            'applicant_message' => ['nullable', 'string', 'max:1000'],
            'version' => ['required', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [...$this->versionMessages(), 'to.in' => 'Choose a status an application can have.'];
    }
}
