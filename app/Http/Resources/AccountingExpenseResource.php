<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One expense, shaped as `Expense` in `src/types/accounting.ts`.
 *
 * Matched field for field against that interface and against the table in
 * `src/app/(app)/accounting/expenses/page.tsx`, which reads `date`, `payee`,
 * `expense_account_name`, `due_date`, `status`, `amount` and `amount_due`.
 *
 * ## Two things the screen depends on that are easy to get wrong
 *
 * 1. **`amount`, `amount_paid` and `amount_due` are INTEGERS of centavos.** A
 *    `decimal:2` cast would serialise as the string "15000.50", which the
 *    screen's centavo formatter would show as ₱150.01 — a hundredfold error
 *    that looks like a plausible figure. The model casts them to `integer` and
 *    this emits them as such. The page's "Outstanding" figure is the list's
 *    `meta.totals.amount_due`, summed on the server over every matching
 *    expense; the screen adds nothing up.
 * 2. **`status` is derived, not stored.** The column holds only the settlement
 *    state; `overdue` is a function of `due_date` against today. See
 *    AccountingExpense::status().
 */
class AccountingExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // `date:Y-m-d` casts, so these are already "2026-09-18" rather than
            // an ISO instant that `formatDate()` could render a day early.
            'date' => $this->date?->toDateString(),
            'payee' => $this->payee,
            'expense_account_id' => $this->expense_account_id,
            // The table renders `expense_account_name ?? "—"`. Eager-loaded by
            // the controller — `whenLoaded` rather than a lazy read, because the
            // list page would otherwise issue one query per row.
            'expense_account_name' => $this->whenLoaded(
                'expenseAccount',
                fn (): ?string => $this->expenseAccount?->name,
            ),
            // CENTAVOS, as integers.
            'amount' => (int) $this->amount,
            'amount_paid' => (int) $this->amount_paid,
            // What is still owed (AccountingExpense::outstanding()), so the
            // screen never subtracts.
            'amount_due' => $this->outstanding(),
            'payment_account_id' => $this->payment_account_id,
            'branch_id' => $this->branch_id,
            'reference' => $this->reference,
            'description' => $this->description,
            'due_date' => $this->due_date?->toDateString(),
            // One of unpaid | partially_paid | paid | overdue.
            'status' => $this->status(),
            'journal_id' => $this->journal_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
