<?php

namespace App\Http\Requests\Borrower;

use App\Http\Requests\Borrower\Concerns\HasBorrowerRules;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\SavingsAccount;
use App\Models\TimeDeposit;
use App\Rules\BranchTakesNew;
use App\Rules\NoDuplicateBorrower;
use App\Services\BranchAccess;
use App\Services\Branches\BranchWriteGuard;
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

    /**
     * Loan applications that pin a member to their branch: not yet released.
     * Released loans still being paid are Loan::COLLECTIBLE_STATUSES. Closed
     * loans (completed, rejected, void, restructured) don't pin.
     */
    private const UNRELEASED_LOAN_STATUSES = ['draft', 'for_review', 'approved'];

    /** Time deposits not yet settled (paid out, rolled over or preterminated). */
    private const OPEN_TIME_DEPOSIT_STATUSES = ['active', 'matured'];

    /**
     * A member with a loan in progress, an open savings account (active,
     * dormant or frozen) or an open time deposit keeps their branch
     * (multi-branch audit D5). Each of those stays in the branch it was opened
     * in, and nothing moves one, so moving the member alone would leave the
     * old branch holding accounts of a member it can no longer open. The
     * refusal says what blocks the move (D11).
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

                $blockers = self::branchMoveBlockers($borrower);

                if ($blockers !== []) {
                    $validator->errors()->add('branch_id', sprintf(
                        "This member can't move to another branch while they have %s. Loans, savings and time deposits stay in the branch where they were opened, so the member keeps that branch until these are closed.",
                        self::listInWords($blockers),
                    ));
                }
            },
        ];
    }

    /**
     * What pins the member to their branch, in words ("1 loan still being
     * paid", "2 open savings accounts"); empty when they may move.
     *
     * @return list<string>
     */
    private static function branchMoveBlockers(Borrower $borrower): array
    {
        $counts = [
            ['loan still being paid', 'loans still being paid', Loan::query()->where('borrower_id', $borrower->id)->whereIn('status', Loan::COLLECTIBLE_STATUSES)->count()],
            ['loan application not yet released', 'loan applications not yet released', Loan::query()->where('borrower_id', $borrower->id)->whereIn('status', self::UNRELEASED_LOAN_STATUSES)->count()],
            ['open savings account', 'open savings accounts', SavingsAccount::query()->where('borrower_id', $borrower->id)->where('status', '!=', SavingsAccount::STATUS_CLOSED)->count()],
            ['open time deposit', 'open time deposits', TimeDeposit::query()->where('borrower_id', $borrower->id)->whereIn('status', self::OPEN_TIME_DEPOSIT_STATUSES)->count()],
        ];

        $blockers = [];

        foreach ($counts as [$one, $many, $count]) {
            if ($count > 0) {
                $blockers[] = $count.' '.($count === 1 ? $one : $many);
            }
        }

        return $blockers;
    }

    /** @param  list<string>  $items */
    private static function listInWords(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items).' and '.$last;
    }

    public function messages(): array
    {
        return $this->borrowerMessages();
    }
}
