<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One chart-of-accounts row, shaped as `Account` in `src/types/accounting.ts`.
 *
 * Two optional fields of that interface are deliberately NOT emitted here:
 *
 * - `has_transactions` — there is no journal table yet, so there is no honest
 *   answer. Sending `false` would be a claim rather than a fact, and the UI
 *   uses it to decide whether Delete is safe. Whoever lands journals should add
 *   it here as an `exists` against posted lines.
 * - `balance` — a reporting figure the trial-balance and ledger endpoints
 *   attach, not part of the account. A chart row fetched for a picker has no
 *   balance and needs none.
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
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
