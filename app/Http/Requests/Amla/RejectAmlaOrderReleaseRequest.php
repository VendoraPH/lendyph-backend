<?php

namespace App\Http\Requests\Amla;

/**
 * Reject an order's release: the note saying why is required.
 */
class RejectAmlaOrderReleaseRequest extends DecideAmlaOrderReleaseRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['note' => ['required', 'string', 'max:1000']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['note.required' => 'Say why the release is rejected.'];
    }
}
