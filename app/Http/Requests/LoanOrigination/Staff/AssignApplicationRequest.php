<?php

namespace App\Http\Requests\LoanOrigination\Staff;

/**
 * `POST /loan-origination/applications/{id}/assign`: the officer (null
 * clears it) and the version the client read. Who may be picked is
 * StaffProcessing::canBeAssigned().
 */
class AssignApplicationRequest extends StaffWriteRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'officer_id' => ['present', 'nullable', 'integer', 'min:1'],
            'version' => ['required', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->versionMessages();
    }
}
