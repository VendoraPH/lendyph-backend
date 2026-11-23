<?php

namespace App\Http\Requests\LoanOrigination\Staff;

use App\Services\LoanOrigination\Staff\StaffAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A staff read of online applications: `loan_origination:view`, refused in
 * words (StaffAccess).
 */
abstract class StaffViewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return StaffAccess::canView($this->user());
    }

    /** @throws AuthorizationException */
    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(StaffAccess::VIEW_REFUSAL);
    }

    /** The requested page size, clamped to 1..100 like every other list. */
    public function perPage(): int
    {
        return min(max((int) ($this->validated('per_page') ?? 25), 1), 100);
    }
}
