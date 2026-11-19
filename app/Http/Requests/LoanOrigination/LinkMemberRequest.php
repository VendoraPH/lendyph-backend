<?php

namespace App\Http\Requests\LoanOrigination;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /loan-origination/applications/{application}/link-member: an existing
 * member's id, or null to unlink, and the application version the client
 * read. Permissions and the member checks are MemberLink's, in words.
 */
class LinkMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'borrower_id' => ['present', 'nullable', 'integer', 'min:1'],
            'version' => ['required', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'borrower_id.present' => 'Choose a member to link, or none to unlink.',
            'borrower_id.integer' => 'Choose a member from the list.',
            'borrower_id.min' => 'Choose a member from the list.',
            'version.required' => 'Refresh the application and try again.',
            'version.integer' => 'Refresh the application and try again.',
            'version.min' => 'Refresh the application and try again.',
        ];
    }
}
