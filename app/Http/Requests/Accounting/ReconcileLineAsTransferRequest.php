<?php

namespace App\Http\Requests\Accounting;

use App\Http\Requests\Concerns\RefusesAClosedBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Recording an unmatched statement line as a transfer between the reconciled
 * account and another of the organisation's money accounts.
 *
 * Needs `cash_accounts:transfer`, as the Cash & Bank transfer does, on top of
 * reconciliation's `accounting:reconcile`. The transfer posts in the
 * reconciliation's branch.
 */
class ReconcileLineAsTransferRequest extends FormRequest
{
    use RefusesAClosedBranch;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user->can('accounting:reconcile')
            && $user->can('cash_accounts:transfer');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // Checked as a MONEY account other than the reconciled one by
            // ReconciliationActions, so the 422 names this field.
            'counter_account_id' => ['required', 'integer', 'exists:accounting_accounts,id'],
            'description' => ['nullable', 'string', 'max:500'],
            // Go ahead even though the line matches an entry already in the
            // books (otherwise a 422 on `line` offers that entry instead).
            'record_anyway' => ['nullable', 'boolean'],
        ];
    }

    /** Posts into the reconciliation's branch, which a closed branch refuses (Phase 6 MB-BE2). */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->refuseAClosedBranch($validator, $this->route('reconciliation')?->branch_id)];
    }
}
