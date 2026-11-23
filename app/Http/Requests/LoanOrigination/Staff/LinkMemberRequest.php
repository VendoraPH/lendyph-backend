<?php

namespace App\Http\Requests\LoanOrigination\Staff;

use App\Services\LoanOrigination\Staff\StaffAccess;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * `POST /loan-origination/applications/{id}/link-member`: the member (null
 * clears the link) and the version the client read. Needs
 * `loan_origination:update` and `borrowers:view`; the member must be in the
 * caller's branches (StaffProcessing::linkMember()).
 */
class LinkMemberRequest extends StaffWriteRequest
{
    public function authorize(): bool
    {
        return StaffAccess::canUpdate($this->user()) && StaffAccess::canSeeMembers($this->user());
    }

    /** @throws AuthorizationException */
    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(StaffAccess::canUpdate($this->user()) ? StaffAccess::MATCHES_REFUSAL : StaffAccess::UPDATE_REFUSAL);
    }

    /** @return array<string, mixed> */
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
        return $this->versionMessages();
    }
}
