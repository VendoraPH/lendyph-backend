<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One payment of an expense, shaped as `ExpensePayment` in
 * `src/types/accounting.ts`, inside AccountingExpenseResource's `payments`.
 *
 * `reversed` is read from the payment's journal (AccountingExpensePayment::
 * isReversed()): a reversed payment no longer counts as paid. `can_reverse`
 * tells the screen whether Reverse payment is open: the journal stands.
 * Whether the user may take it (`expenses:pay` and `journals:reverse`) is
 * the screen's to check, and the server's to enforce. Amounts are INTEGER
 * CENTAVOS.
 */
class AccountingExpensePaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $reversed = $this->isReversed();

        return [
            'id' => $this->id,
            'date' => $this->date?->toDateString(),
            'amount' => (int) $this->amount,
            'payment_account_id' => $this->payment_account_id,
            'payment_account_name' => $this->whenLoaded('paymentAccount', fn (): ?string => $this->paymentAccount?->name),
            'journal_id' => $this->journal_id,
            // The number to find the entry by on the Journals screen.
            'journal_no' => $this->journal_id === null ? null : $this->journal?->journal_no,
            'reversed' => $reversed,
            'can_reverse' => $this->journal_id !== null && ! $reversed,
        ];
    }
}
