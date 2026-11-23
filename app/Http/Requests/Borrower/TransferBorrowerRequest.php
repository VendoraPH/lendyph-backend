<?php

namespace App\Http\Requests\Borrower;

use App\Services\BranchAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Move a member to another branch (Phase 6, multi-branch decision 7):
 * `borrowers:transfer` plus `borrowers:view` (the answer is the member's
 * full record), a destination among the caller's own branches (any with
 * `branches:view_all`) and a reason.
 *
 * The member's own branch is checked by the route binding: a member the
 * caller can't see answers 404. A destination the caller can't see reads
 * like a missing one. What may block the move is MemberTransfer's.
 */
class TransferBorrowerRequest extends FormRequest
{
    public const REFUSAL = 'Transferring a member to another branch needs the member transfer and member view permissions.';

    public function authorize(): bool
    {
        return $this->user()->can('borrowers:transfer') && $this->user()->can('borrowers:view');
    }

    /** @throws AuthorizationException */
    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(self::REFUSAL);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => [
                'required',
                'integer',
                app(BranchAccess::class)->constrainBranchExists(Rule::exists('branches', 'id'), $this->user()),
            ],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'branch_id.required' => 'Choose the branch to move this member to.',
            'branch_id.integer' => 'Choose one of your branches.',
            'branch_id.exists' => 'Choose one of your branches.',
            'reason.required' => 'Give a reason for the transfer.',
            'reason.string' => 'Give a reason for the transfer.',
            'reason.max' => 'The reason can be at most 500 characters.',
        ];
    }
}
