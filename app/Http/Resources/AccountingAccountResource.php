<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One chart-of-accounts row, shaped as `Account` in `src/types/accounting.ts`.
 *
 * Two of its fields are CONDITIONAL, and both are absent rather than guessed
 * when the controller has not supplied them:
 *
 * - `has_transactions` needs `withCount('journalLines')`. Emitting `false`
 *   without the count would be a claim rather than a fact, and the claim it
 *   makes is "deleting this is safe".
 * - `balance` needs the trial balance. It is a reporting figure the list
 *   endpoints attach, not part of the account — a chart row fetched for a
 *   picker has no balance and needs none.
 *
 * `MissingValue` (what `when`/`whenCounted` return) drops the key from the JSON
 * entirely, so `account.balance ?? 0` on the client reads as "not asked for"
 * rather than as zero pesos.
 */
class AccountingAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'normal_balance' => $this->normal_balance,
            'is_contra' => (bool) $this->is_contra,
            'parent_id' => $this->parent_id,
            'is_group' => (bool) $this->is_group,
            'is_active' => (bool) $this->is_active,
            'cash_kind' => $this->cash_kind,
            'description' => $this->description,
            // Whether any journal line — draft or posted — references this
            // account. The read side of the restricting foreign key that stops
            // an account with history being deleted.
            'has_transactions' => $this->whenCounted(
                'journalLines',
                fn (): bool => (int) $this->journal_lines_count > 0,
            ),
            // CENTAVOS, as an integer, signed in the account's own normal
            // direction. Never a decimal string: a `decimal:2` cast serialises
            // to JSON as `"1500.50"`, and `sumCentavos` on the frontend had to
            // be hardened against exactly that after a Cash & Bank total read
            // ₱0.00 while every row beneath it formatted correctly.
            //
            // Set by the controller from TrialBalanceBuilder, so this figure and
            // the trial balance are the same arithmetic over the same rows —
            // there is no second aggregate that could drift from it.
            'balance' => $this->when(
                $this->balance !== null,
                fn (): int => (int) $this->balance,
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
