<?php

namespace App\Http\Requests\LoanOrigination\Staff;

use App\Models\OriginationSetting;
use Illuminate\Validation\Rule;

/**
 * `POST /loan-origination/applications/{id}/request-information`: the
 * message for the applicant (logged, never sent), the document types to ask
 * for again (the settings' types only) and the version the client read.
 */
class RequestInformationRequest extends StaffWriteRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:1000'],
            'document_types' => ['nullable', 'array', 'max:20'],
            'document_types.*' => ['required', 'string', 'distinct', Rule::in(OriginationSetting::current()->documentTypeKeys())],
            'version' => ['required', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...$this->versionMessages(),
            'message.required' => 'Write what the applicant should send.',
            'document_types.*.in' => 'Choose a document type from the online application settings.',
        ];
    }

    /** @return list<string> */
    public function documentTypes(): array
    {
        return array_values(array_map('strval', (array) ($this->validated('document_types') ?? [])));
    }
}
