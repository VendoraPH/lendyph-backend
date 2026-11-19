<?php

namespace App\Http\Requests\Borrower;

use App\Models\Borrower;
use App\Rules\BranchTakesNew;
use App\Services\BranchAccess;
use App\Services\Branches\BranchWriteGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
                'bail',
                'nullable',
                'integer',
                app(BranchAccess::class)->constrainBranchExists(Rule::exists('branches', 'id'), $this->user()),
                BranchTakesNew::activeOnly(BranchWriteGuard::MEMBERS),
            ],
        ];
    }

    /**
     * A registration that already names a branch, approved without choosing
     * one, joins that branch: it waits while the branch is suspended or
     * closed (Phase 6 MB-BE2). The controller keeps the other approval checks.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $registration = $this->route('borrower');

            if ($this->filled('branch_id') || ! $registration instanceof Borrower || $registration->branch_id === null) {
                return;
            }

            BranchTakesNew::activeOnly(BranchWriteGuard::MEMBERS)->validate(
                'branch_id',
                $registration->branch_id,
                fn (string $message) => $validator->errors()->add('branch_id', $message),
            );
        });
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
