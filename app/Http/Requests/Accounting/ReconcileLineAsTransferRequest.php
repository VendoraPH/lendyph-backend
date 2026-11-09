<?php

namespace App\Http\Requests\Accounting;

use App\Services\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Recording an unmatched statement line as a transfer between the reconciled
 * account and another of the organisation's money accounts.
 *
 * Needs `cash_accounts:transfer`, as the Cash & Bank transfer does, on top of
 * reconciliation's `accounting:reconcile` + `branches:view_all`.
 */
class ReconcileLineAsTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user->can('accounting:reconcile')
            && $user->can(BranchAccess::VIEW_ALL)
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
        ];
    }
}
