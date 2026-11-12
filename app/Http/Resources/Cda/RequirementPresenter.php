<?php

namespace App\Http\Resources\Cda;

use App\Models\CdaComplianceRequirement;
use App\Services\Cda\Deadlines;

/**
 * A custom compliance calendar item as the API sends it (gap round B6), on
 * its own list and as a calendar row (`kind: requirement`). Dates are
 * YYYY-MM-DD; `effective_deadline` is the extension, else the default, and
 * `days_remaining` counts Manila days, negative once past.
 */
final class RequirementPresenter
{
    /** @return array<string, mixed> */
    public static function row(CdaComplianceRequirement $requirement): array
    {
        $effective = $requirement->effectiveDueOn();

        return [
            'kind' => 'requirement',
            'id' => (int) $requirement->id,
            'year' => (int) $requirement->year,
            'title' => $requirement->title,
            'description' => $requirement->description,
            'reference' => $requirement->reference,
            'status' => $requirement->status,
            'display_status' => $requirement->status,
            'applicable' => $requirement->status !== CdaComplianceRequirement::STATUS_NOT_APPLICABLE,
            'filed' => $requirement->status === CdaComplianceRequirement::STATUS_FILED,
            'filed_on' => $requirement->filed_on?->toDateString(),
            'default_due_on' => $requirement->default_due_on->toDateString(),
            'extended_due_on' => $requirement->extended_due_on?->toDateString(),
            'effective_deadline' => $effective,
            'days_remaining' => Deadlines::daysRemaining($effective),
            'created_by' => CdaPresenter::user($requirement->creator),
            'updated_by' => CdaPresenter::user($requirement->updater),
            'created_at' => $requirement->created_at,
            'updated_at' => $requirement->updated_at,
        ];
    }
}
