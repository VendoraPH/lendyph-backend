<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One posting in an account's history, shaped as `LedgerEntry` in
 * `src/types/accounting.ts`.
 *
 * Wraps a plain array rather than a model: the general ledger is a join with a
 * running balance that only exists in the context of the ordered set it came
 * from, so there is no row in any table this could be a resource FOR. It exists
 * so the ledger answers with the same `{data, links, meta}` envelope as every
 * other list in the module, and so the centavo fields are cast in one place.
 *
 * @property array<string, mixed> $resource
 */
class LedgerEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return [
            'journal_id' => (int) $row['journal_id'],
            'journal_no' => (string) $row['journal_no'],
            'date' => (string) $row['date'],
            'source' => (string) $row['source'],
            'reference' => $row['reference'],
            'description' => (string) $row['description'],
            'branch_id' => $row['branch_id'],
            'debit' => (int) $row['debit'],
            'credit' => (int) $row['credit'],
            // Centavos, signed in the ACCOUNT's normal direction and carrying
            // everything before it — including movements outside the requested
            // page and outside the requested date range. See
            // GeneralLedgerBuilder.
            'running_balance' => (int) $row['running_balance'],
        ];
    }
}
