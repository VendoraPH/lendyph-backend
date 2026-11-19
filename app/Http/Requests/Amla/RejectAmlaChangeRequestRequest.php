<?php

namespace App\Http\Requests\Amla;

/**
 * Reject an AMLA change request: the note saying why is required.
 */
class RejectAmlaChangeRequestRequest extends DecideAmlaChangeRequestRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['note' => ['required', 'string', 'max:1000']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['note.required' => 'Say why the change is rejected.'];
    }
}
