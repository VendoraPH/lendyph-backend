<?php

namespace App\Http\Requests\LoanOrigination\Staff;

use App\Services\LoanOrigination\Staff\StaffAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A staff write on an online application: `loan_origination:update`,
 * refused in words (StaffAccess). Rejecting also needs `loans:reject`,
 * which ApplicationTransitions checks.
 */
abstract class StaffWriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return StaffAccess::canUpdate($this->user());
    }

    /** @throws AuthorizationException */
    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(StaffAccess::UPDATE_REFUSAL);
    }

    /** @return array<string, string> */
    protected function versionMessages(): array
    {
        return [
            'version.required' => 'Send the application version you read, so a change made meanwhile isn\'t overwritten.',
            'version.integer' => 'Send the application version you read, so a change made meanwhile isn\'t overwritten.',
        ];
    }
}
