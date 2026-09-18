<?php

namespace App\Http\Requests\Accounting;

use App\Services\Accounting\Money;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Recording an expense.
 *
 * The payload is what `ExpenseDialog` in
 * `src/app/(app)/accounting/_components/expense-dialog.tsx` sends: `date`,
 * `payee`, `expense_account_id`, `amount` (already through `toCentavos`),
 * `payment_account_id` (null when the "Paid now?" switch is off), `due_date`,
 * `reference`, `description`.
 *
 * What the ACCOUNTS have to be — an expense account for the cost, a money
 * account for the payment — is checked in ExpenseRecorder rather than here,
 * with `exists` below as the cheap first pass. Two reasons: the recorder is
 * also reached by the pay endpoint and by any future importer, and the messages
 * it raises explain the accounting consequence rather than saying "invalid".
 */
class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('expenses:create');
    }

    public function rules(): array
    {
        return [
            // A calendar date, not an instant. `date_format` rather than `date`
            // so "next tuesday" and a full ISO timestamp are both refused —
            // either would be stored as a day nobody chose.
            'date' => ['required', 'date_format:Y-m-d'],
            'payee' => ['required', 'string', 'max:160'],
            'expense_account_id' => ['required', 'integer', 'exists:accounting_accounts,id'],
            /*
             * INTEGER CENTAVOS. ₱15,000.50 arrives as 1500050.
             *
             * `integer` is doing real work: a client that skipped `toCentavos`
             * and sent 15000.50 is REFUSED rather than quietly floored to
             * ₱150.00, which is the 100x error this module's money convention
             * exists to make impossible. `min:1` because a zero-peso expense
             * has no journal that could be written for it.
             *
             * `max:` is the same ceiling every journal line carries — past 2^53
             * centavos the poster's own recomputed totals stop being exact.
             */
            'amount' => ['required', 'integer', 'min:1', 'max:'.Money::maxCentavos()],
            // Null means "not paid yet" — the accrual path. Absent means the
            // same thing, which is why this is `nullable` and not `sometimes`.
            'payment_account_id' => ['nullable', 'integer', 'exists:accounting_accounts,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'reference' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:500'],
            // Only meaningful on an accrual; the recorder drops it on a cash
            // expense so a settled cost can never read as overdue.
            'due_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('payee')) {
            $this->merge(['payee' => trim((string) $this->input('payee'))]);
        }
    }
}
