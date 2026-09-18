<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One side of one entry, shaped as `JournalLine` in `src/types/accounting.ts`.
 *
 * `account_code` and `account_name` are DENORMALISED onto the line on purpose:
 * the journal register renders a code and a name per line, and the frontend
 * drains every page of it (`journalsListAll`). Without these, each page would
 * either arrive incomplete or force the client to hold the whole chart and join
 * it itself. The relation is eager-loaded by the controller — see
 * AccountingJournal::scopeWithRegisterRelations() — so this costs no query.
 *
 * Both are `whenLoaded`, not lazily fetched, so a missing eager-load shows up
 * as an absent field in a test rather than as N+1 queries in production.
 */
class JournalLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => (int) $this->accounting_account_id,
            'account_code' => $this->whenLoaded('account', fn () => $this->account->code),
            'account_name' => $this->whenLoaded('account', fn () => $this->account->name),
            'description' => $this->description,
            // Centavos, as integers. Never a decimal string: `sumCentavos` on
            // the frontend was hardened against exactly that because a total
            // once read zero while every row beneath it formatted correctly.
            'debit' => (int) $this->debit,
            'credit' => (int) $this->credit,
        ];
    }
}
