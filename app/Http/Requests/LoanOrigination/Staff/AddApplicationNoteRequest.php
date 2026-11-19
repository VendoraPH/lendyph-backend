<?php

namespace App\Http\Requests\LoanOrigination\Staff;

/** `POST /loan-origination/applications/{id}/notes`: an internal note. */
class AddApplicationNoteRequest extends StaffWriteRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:2000']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['body.required' => 'Write the note.'];
    }
}
