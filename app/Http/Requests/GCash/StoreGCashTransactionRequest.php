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

                $this->checkBranch($validator);
            },
        ];
    }

    /**
     * The branch a walk-in transaction is recorded in, for its journal (O4
     * review, 2026-11-11): the one sent, else the caller's only branch. Null
     * for a member's transaction (the member's branch decides) and for a
     * `branches:view_all` caller who picks none (head office).
     */
    public function walkInBranchId(): ?int
    {
        if (! $this->filled('gcash_non_member_id')) {
            return null;
        }

        if ($this->filled('branch_id')) {
            return (int) $this->input('branch_id');
        }

        $branchIds = app(BranchAccess::class)->for($this->user());

        return $branchIds !== null && count($branchIds) === 1 ? $branchIds[0] : null;
    }

    /**
     * A member's transaction takes no branch. A walk-in's needs one the
     * caller holds: picked when they hold several, refused when they hold
     * none. `branches:view_all` may pick any branch, or none.
     */
    private function checkBranch(Validator $validator): void
    {
        if ($validator->errors()->has('branch_id')) {
            return;
        }

        if ($this->filled('borrower_id')) {
            if ($this->filled('branch_id')) {
                $validator->errors()->add('branch_id', "A member's transaction is in the member's branch. Leave the branch out.");
            }

            return;
        }

        $branchIds = app(BranchAccess::class)->for($this->user());

        if ($branchIds === null || $this->filled('branch_id')) {
            return;
        }

        if ($branchIds === []) {
            $validator->errors()->add(
                'branch_id',
                'You have no branch assigned, so you cannot record a walk-in transaction. Ask an administrator to assign you a branch.',
            );
        } elseif (count($branchIds) > 1) {
            $validator->errors()->add('branch_id', 'Pick the branch where this walk-in transaction is done.');
        }
    }
}
