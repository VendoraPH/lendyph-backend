<?php

namespace App\Http\Requests\Accounting;

use App\Http\Requests\Concerns\RefusesAClosedBranch;
use App\Services\Accounting\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Settling part or all of an accrued expense.
 *
 * The payload matches `accountingService.payExpense(id, { date, amount,
 * account_id })` in `src/services/accounting.service.ts` exactly — including
 * the name `account_id` rather than `payment_account_id`, which is the client's
 * spelling and therefore the contract.
 *
 * `amount` is checked against what is actually outstanding in ExpenseRecorder,
 * under a row lock, not here: the answer depends on rows that can change
 * between validation and the write, and two people paying the same bill at once
 * is exactly the case a stateless check cannot see.
 */
class PayExpenseRequest extends FormRequest
{
    use RefusesAClosedBranch;

    public function authorize(): bool
    {
        return $this->user()->can('expenses:pay');
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            // INTEGER CENTAVOS — see StoreExpenseRequest for why `integer` is
            // load-bearing rather than defensive.
            'amount' => ['required', 'integer', 'min:1', 'max:'.Money::maxCentavos()],
            // The money account the payment comes out of. Checked as a MONEY
            // account (postable, active, carrying a `cash_kind`) in the
            // recorder; `exists` is the cheap first pass.
            'account_id' => ['required', 'integer', 'exists:accounting_accounts,id'],
        ];
    }

    /** Posts into the expense's branch, which a closed branch refuses (Phase 6 MB-BE2). */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->refuseAClosedBranch($validator, $this->route('expense')?->branch_id)];
    }
}
