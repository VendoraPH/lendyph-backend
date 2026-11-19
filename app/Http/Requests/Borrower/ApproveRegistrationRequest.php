<?php

namespace App\Http\Requests\Borrower;

use App\Services\BranchAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Approving a public registration (Phase 6 FX-1, D7).
 *
 * A registration may arrive with no branch, and approving it without one left
 * a member no branch user could see, whose first loan then failed on the NOT
 * NULL `loans.branch_id`. So the reviewer names the branch here, from their
 * own branches (any branch with `branches:view_all`). Whether one is required
 * depends on the registration, so BorrowerController::approveRegistration()
 * makes that check once the status and valid ID checks pass.
 */
class ApproveRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('borrowers:approve') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'branch_id' => [
                'nullable',
                'integer',
                app(BranchAccess::class)->constrainBranchExists(Rule::exists('branches', 'id'), $this->user()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'branch_id.exists' => 'Choose one of your own branches for this member.',
            'branch_id.integer' => 'Choose one of your own branches for this member.',
        ];
    }
}
