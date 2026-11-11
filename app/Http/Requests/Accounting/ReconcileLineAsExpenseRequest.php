<?php

namespace App\Http\Requests\Accounting;

use App\Services\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Recording an unmatched statement line (money out) as an expense paid from
 * the reconciled account.
 *
 * Reconciliation is head office (`accounting:reconcile` + `branches:view_all`),
 * and the expense it writes needs the same `expenses:create` the Expenses
 * screen does (Accounting round C, 2026-11-09).
 */
class ReconcileLineAsExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user->can('accounting:reconcile')
            && $user->can(BranchAccess::VIEW_ALL)
            && $user->can('expenses:create');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // Checked as an EXPENSE account by ExpenseRecorder.
            'expense_account_id' => ['required', 'integer', 'exists:accounting_accounts,id'],
            // 160, the column's width and the Expenses screen's own limit.
            'payee' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
            // Go ahead even though the line matches an entry already in the
            // books (otherwise a 422 on `line` offers that entry instead).
            'record_anyway' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('payee')) {
            $this->merge(['payee' => trim((string) $this->input('payee'))]);
        }
    }
}
