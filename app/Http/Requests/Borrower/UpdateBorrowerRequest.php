<?php

namespace App\Http\Requests\Borrower;

use App\Http\Requests\Borrower\Concerns\HasBorrowerRules;
use App\Models\Borrower;
use App\Models\Loan;
use App\Rules\NoDuplicateBorrower;
use App\Services\BranchAccess;
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
                'membership_date' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:'.now()->toDateString()],
                // One of the caller's own branches (BranchAccess; any with
                // `branches:view_all`).
                'branch_id' => [
                    'sometimes',
                    app(BranchAccess::class)->constrainBranchExists(Rule::exists('branches', 'id'), $this->user()),
                ],
            ],
        );
    }

    /**
     * Loan statuses that pin a member to their branch: every loan still in
     * progress, from application to collection. Closed loans (completed,
     * rejected, void, restructured) don't.
     */
    private const BRANCH_BOUND_LOAN_STATUSES = [
        'draft', 'for_review', 'approved',
        ...Loan::COLLECTIBLE_STATUSES,
    ];

    /**
     * A member with a loan in progress keeps their branch. Loans keep the
     * branch they were opened in, so moving the member alone would split the
     * member from their open loans and applications across two branches'
     * staff.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $borrower = $this->route('borrower');

                if (! $borrower instanceof Borrower || ! $this->filled('branch_id')
                    || $validator->errors()->has('branch_id')
                    || (int) $this->input('branch_id') === (int) $borrower->branch_id) {
                    return;
                }

                $hasOpenLoans = $borrower->loans()
                    ->whereIn('status', self::BRANCH_BOUND_LOAN_STATUSES)
                    ->exists();

                if ($hasOpenLoans) {
                    $validator->errors()->add('branch_id', "Close or move this member's open loans first.");
                }
            },
        ];
    }

    public function messages(): array
    {
        return $this->borrowerMessages();
    }
}
