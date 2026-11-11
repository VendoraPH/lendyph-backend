<?php

namespace App\Http\Requests\GCash;

use App\Http\Requests\Concerns\ExcludesRejectedBorrowers;
use App\Http\Requests\GCash\Concerns\HasGCashQuoteRules;
use App\Services\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreGCashTransactionRequest extends FormRequest
{
    use ExcludesRejectedBorrowers;
    use HasGCashQuoteRules;

    /** Set by resolveBranch() once validation has passed. */
    private ?int $resolvedBranchId = null;

    public function authorize(): bool
    {
        return $this->user()->can('gcash:transact');
    }

    /**
     * A transaction has exactly one party: a member (`borrower_id`) or a
     * walk-in (`gcash_non_member_id`). `required_without` makes either one
     * acceptable; the `after` hook below rejects sending both, which
     * `required_without` alone permits.
     */
    public function rules(): array
    {
        return [
            'borrower_id' => [
                'required_without:gcash_non_member_id',
                'nullable',
                'integer',
                $this->nonRejectedBorrowerRule(),
            ],
            'gcash_non_member_id' => [
                'required_without:borrower_id',
                'nullable',
                'integer',
                Rule::exists('gcash_non_members', 'id')->whereNull('deleted_at'),
            ],
            ...$this->quoteRules(),
            // A walk-in transaction's branch: one of the caller's. See after().
            'branch_id' => [
                'nullable', 'integer',
                app(BranchAccess::class)->constrainBranchExists(Rule::exists('branches', 'id'), $this->user()),
            ],
            'is_pending' => ['nullable', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Staff know the second kind of party as a walk-in, so the generated
     * messages name the field that way instead of "gcash non member id".
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'gcash_non_member_id' => 'walk-in',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->filled('borrower_id') && $this->filled('gcash_non_member_id')) {
                    $validator->errors()->add(
                        'gcash_non_member_id',
                        'A transaction belongs to either a member or a walk-in, not both.',
                    );

                    return;
                }

                $this->resolveBranch($validator);
            },
        ];
    }

    /**
     * The validated data, with `branch_id` set to the branch the transaction
     * is recorded in ({@see self::resolveBranch()}): worked out once, after
     * validation, so what is checked and what is saved can't differ.
     */
    public function validated($key = null, $default = null)
    {
        return data_get(['branch_id' => $this->resolvedBranchId] + parent::validated(), $key, $default);
    }

    /**
     * The branch a walk-in transaction is recorded in, for its journal (O4
     * review, 2026-11-11), or an error. A member's transaction takes no
     * branch (the member's decides), and sending one is refused.
     *
     * - A branch sent is used (the rule above already limits it to the
     *   caller's own branches).
     * - `branches:view_all`: no `branch_id` key means their only assigned
     *   branch, or none when they have no assigned branch or several;
     *   `branch_id: null` sent on purpose means head office (none).
     * - Anyone else: their only branch; refused when they have several
     *   (pick one) or none.
     */
    private function resolveBranch(Validator $validator): void
    {
        $this->resolvedBranchId = null;

        if ($validator->errors()->has('branch_id')) {
            return;
        }

        if ($this->filled('borrower_id')) {
            if ($this->filled('branch_id')) {
                $validator->errors()->add('branch_id', "A member's transaction is in the member's branch. Leave the branch out.");
            }

            return;
        }

        if ($this->filled('branch_id')) {
            $this->resolvedBranchId = (int) $this->input('branch_id');

            return;
        }

        $user = $this->user();
        $branchIds = app(BranchAccess::class)->for($user);

        if ($branchIds === null) {
            if (! $this->has('branch_id')) {
                $assigned = BranchAccess::assignedBranchIds($user, $user->branches()->pluck('branches.id'));
                $this->resolvedBranchId = count($assigned) === 1 ? $assigned[0] : null;
            }

            return;
        }

        if ($branchIds === []) {
            $validator->errors()->add(
                'branch_id',
                'You have no branch assigned, so you cannot record a walk-in transaction. Ask an administrator to assign you a branch.',
            );
        } elseif (count($branchIds) > 1) {
            $validator->errors()->add('branch_id', 'Pick the branch where this walk-in transaction is done.');
        } else {
            $this->resolvedBranchId = $branchIds[0];
        }
    }
}
