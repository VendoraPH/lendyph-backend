<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One journal entry, shaped as `JournalEntry` in `src/types/accounting.ts`.
 *
 * ## `created_by` / `posted_by` / `branch_name` are NAMES, not ids
 *
 * All three are typed `string | null` in the contract, and the register renders
 * them straight into a column. Emitting an integer id would satisfy neither the
 * type nor the screen. The ids are already on the row for anyone who needs to
 * follow them (`branch_id`), and a user id is not something a client can
 * resolve — there is no endpoint that would let it.
 *
 * ## `journal_no` is "" on a draft, not null
 *
 * The contract types it as a non-nullable `string` while documenting that it is
 * assigned on post; the frontend's own draft fixture uses `""`. Null would be
 * the more honest database value and it is what the column holds — but emitting
 * null against a `string` type puts `null` into a template that expects text.
 * Empty string is the shape the client already handles (`entry.journal_no ||
 * "this entry"` in `buildReversal`).
 */
class JournalEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'journal_no' => (string) ($this->journal_no ?? ''),
            'date' => $this->date?->toDateString(),
            'source' => $this->source,
            'reference' => $this->reference,
            'description' => $this->description,
            'branch_id' => $this->branch_id,
            'branch_name' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'status' => $this->status,
            'lines' => JournalLineResource::collection($this->whenLoaded('lines')),
            // Centavos, server-computed from the persisted lines on post. Equal
            // on anything that is not a draft.
            'total_debit' => (int) $this->total_debit,
            'total_credit' => (int) $this->total_credit,
            'reverses_journal_id' => $this->reverses_journal_id,
            'reversed_by_journal_id' => $this->reversed_by_journal_id,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->full_name),
            'created_at' => $this->created_at,
            'posted_by' => $this->whenLoaded('poster', fn () => $this->poster?->full_name),
            'posted_at' => $this->posted_at,
        ];
    }
}
