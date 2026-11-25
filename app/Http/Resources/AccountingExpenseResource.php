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
 * 2. **`status` and `amount_paid` are derived, not stored.** `amount_paid` is
 *    what has been paid and still stands (AccountingExpense::paidAmount()):
 *    a payment whose journal stands reversed doesn't count. `overdue` is a
 *    function of `due_date` against today, and `reversed` of the journal's
 *    status. See AccountingExpense::status(). The controller eager-loads
 *    AccountingExpense::STATUS_RELATIONS for them.
 *
 * ## Which actions are open, from the server's own rules (finish run round 5)
 *
 * - `can_edit`: false once the expense stands reversed (the edit answers 422).
 * - `can_reverse`: Reverse expense can be taken now: the expense has a journal
 *   that stands, and none of its payments stands.
 * - `reverse_blocked_reason`: while payments stand, why Reverse expense is
 *   refused, in words ("Reverse its payments first: …"), for a disabled
 *   button; null otherwise. With `can_reverse` false and no reason, the
 *   action doesn't apply (already reversed, or no journal) and is hidden.
 * - each payment's `can_reverse` (AccountingExpensePaymentResource).
 *
 * Whether the user may take each one is the screen's to check from the
 * permissions, and the server's to enforce.
 */
class AccountingExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $reversed = $this->isReversed();
        $reversible = $this->journal_id !== null && ! $reversed;
        $blockedReason = $reversible ? $this->reverseBlockedReason() : null;

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
            // What has been paid and still stands (AccountingExpense::
            // paidAmount()), never the stored column.
            'amount_paid' => $this->paidAmount(),
            // What is still owed (AccountingExpense::amountDue()), so the
            // screen never subtracts: 0 while the journal stands reversed.
            'amount_due' => $this->amountDue(),
            'payment_account_id' => $this->payment_account_id,
            'branch_id' => $this->branch_id,
            'reference' => $this->reference,
            'description' => $this->description,
            'due_date' => $this->due_date?->toDateString(),
            // One of unpaid | partially_paid | paid | overdue | reversed.
            'status' => $this->status(),
            'can_edit' => ! $reversed,
            'can_reverse' => $reversible && $blockedReason === null,
            'reverse_blocked_reason' => $blockedReason,
            // Every payment, reversed ones included, by date.
            'payments' => AccountingExpensePaymentResource::collection($this->paymentsInOrder()),
            'journal_id' => $this->journal_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
