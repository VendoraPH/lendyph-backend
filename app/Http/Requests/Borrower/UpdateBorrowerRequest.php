<?php

namespace App\Http\Requests\Borrower;

use App\Http\Requests\Borrower\Concerns\HasBorrowerRules;
use App\Models\Borrower;
use App\Rules\NoDuplicateBorrower;
use App\Services\BranchAccess;
use Closure;
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
        $sharedRules = $this->sharedBorrowerRules($borrowerId);
        unset($sharedRules['status']);

        return array_merge(
            $this->requireMemberFields($sharedRules, creating: false),
            [
                'first_name' => $firstNameRules,
                'last_name' => ['sometimes', 'string', 'max:255'],
                // CDA member fields (design §1). The membership number and
                // how a membership ended have their own routes.
                'member_type' => ['sometimes', 'string', Rule::in(Borrower::MEMBER_TYPES)],
                'membership_date' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:'.now()->toDateString(), function (string $attribute, mixed $value, Closure $fail): void {
                    if (in_array($this->route('borrower')?->status, Borrower::NON_MEMBER_STATUSES, true)) {
                        $fail('Only a member has a membership date. Approve the registration first; approving sets it.');
                    }
                }],
                // One of the caller's own branches (BranchAccess; any with
                // `branches:view_all`).
                'branch_id' => [
                    'sometimes',
                    app(BranchAccess::class)->constrainBranchExists(Rule::exists('branches', 'id'), $this->user()),
                ],
            ],
        );
    }

    public const BRANCH_CHANGE_REFUSAL = "A member's branch is changed with Transfer to another branch, not by editing the member. An edit can only fill in a missing branch.";

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
