<?php

namespace App\Http\Requests\Borrower;

use App\Http\Requests\Borrower\Concerns\HasBorrowerRules;
use App\Models\Borrower;
use App\Rules\BranchTakesNew;
use App\Rules\NoDuplicateBorrower;
use App\Services\BranchAccess;
use App\Services\Branches\BranchWriteGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateBorrowerRequest extends FormRequest
{
    use HasBorrowerRules;

    public function authorize(): bool
    {
        return $this->user()->can('borrowers:update');
    }

    public function rules(): array
    {
        $borrowerId = $this->route('borrower')?->id;

        $firstNameRules = ['sometimes', 'string', 'max:255'];

        if (! $this->boolean('force')) {
            $firstNameRules[] = new NoDuplicateBorrower($borrowerId, revealDetails: true, viewer: $this->user());
        }

        // Not `status`: a change of status goes through its own route, so a PUT
        // can't skip `borrowers:approve`, the valid-ID check or `approved_by`.
        // Nor the membership date, member type or membership number: they
        // change through PATCH /borrowers/{id}/membership (MembershipDetails),
        // under the member's row lock and the end-date rule, so a PUT carrying
        // them changes nothing (finish run round 2).
        $sharedRules = $this->sharedBorrowerRules($borrowerId);
        unset($sharedRules['status']);

        return array_merge(
            $this->requireMemberFields($sharedRules, creating: false),
            [
                'first_name' => $firstNameRules,
                'last_name' => ['sometimes', 'string', 'max:255'],
                // One of the caller's own branches (BranchAccess; any with
                // `branches:view_all`).
                // A move into a suspended or closed branch is a new member
                // there (Phase 6 MB-BE2); keeping the member's own is not.
                'branch_id' => [
                    'bail',
                    'sometimes',
                    app(BranchAccess::class)->constrainBranchExists(Rule::exists('branches', 'id'), $this->user()),
                    BranchTakesNew::activeOnly(BranchWriteGuard::MEMBERS, $this->user(), $this->route('borrower')?->branch_id),
                ],
            ],
        );
    }

    public const BRANCH_CHANGE_REFUSAL = 'Use Transfer member to move this member to another branch. Editing the member can only fill in a missing branch.';

    /**
     * A plain edit can only fill in a missing branch (Phase 6, multi-branch
     * decision 7). Moving a member goes through the transfer action
     * (BorrowerTransferController), which checks what the member still has
     * open under a row lock and writes an audit row with a reason. Sending
     * the member's current branch is accepted.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $borrower = $this->route('borrower');

                if (! $borrower instanceof Borrower || ! $this->filled('branch_id')
                    || $validator->errors()->has('branch_id')
                    || $borrower->branch_id === null
                    || (int) $this->input('branch_id') === (int) $borrower->branch_id) {
                    return;
                }

                $validator->errors()->add('branch_id', self::BRANCH_CHANGE_REFUSAL);
            },
        ];
    }

    public function messages(): array
    {
        return $this->borrowerMessages();
    }
}
