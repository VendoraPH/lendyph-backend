<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One accounting period, shaped as `AccountingPeriod` in
 * `src/types/accounting.ts`.
 *
 * Matched against the table in
 * `src/app/(app)/accounting/period-closing/page.tsx`, which reads `code`,
 * `name`, `start_date`, `end_date`, `status`, `closed_at` and `closed_by` — and
 * renders the last two as `${formatDateTime(closed_at)} · ${closed_by}`.
 *
 * `closed_by` is therefore a NAME, not an id. The type says
 * `closed_by?: string | null` and the screen concatenates it straight into that
 * string; an integer there would render as "· 7".
 */
class AccountingPeriodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            // Calendar dates. `formatDate()` renders these directly, so they
            // must not travel as ISO instants that a timezone could shift a day.
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'status' => $this->status,
            // A display name, per the type. Absent relation means null rather
            // than a lazy query per row on a drained list.
            'closed_by' => $this->whenLoaded('closer', fn (): ?string => $this->closer?->full_name),
            // A real instant, rendered by `formatDateTime`.
            'closed_at' => $this->closed_at,
            // Beyond the TypeScript interface, and deliberately. The dialog on
            // that screen promises that reopening is recorded; this is where a
            // reviewer can see it without opening the audit log. Extra keys are
            // ignored by the client.
            'reopened_by' => $this->whenLoaded('reopener', fn (): ?string => $this->reopener?->full_name),
            'reopened_at' => $this->reopened_at,
        ];
    }
}
