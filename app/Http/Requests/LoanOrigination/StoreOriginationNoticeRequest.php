<?php

namespace App\Http\Requests\LoanOrigination;

use App\Models\OriginationNotice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /loan-origination/notices`: publish a new version of a notice.
 * Company-wide, so it needs `loan_origination:settings` plus
 * `branches:view_all`. `final` is true only for the owner's approved wording.
 */
class StoreOriginationNoticeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return OriginationAccess::canChangeCompanyWide($this->user());
    }

    /** @throws AuthorizationException */
    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(OriginationAccess::NOTICE_REFUSAL);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(OriginationNotice::TYPES)],
            'version' => ['required', 'string', 'max:20', 'regex:/^[0-9A-Za-z][0-9A-Za-z.\-]*$/'],
            'title' => ['nullable', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:50000'],
            'final' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'type.in' => 'Choose the privacy notice, terms and conditions, declaration or credit authorization.',
            'version.required' => 'Give this text a version, like 2026.1.',
            'version.regex' => 'A version uses letters, digits, dots and dashes, like 2026.1.',
            'body.required' => 'Enter the text applicants will read.',
            'body.max' => 'The text can be at most 50,000 characters.',
        ];
    }
}
