<?php

namespace App\Services\Cda;

use App\Models\CdaRegulatoryProfile;
use App\Models\CdaReportTemplate;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which template a new report gets (CDA design §2): the active template for
 * the report type whose effective range covers the period's end, whose
 * cooperative types and asset classes include the profile's (an empty list
 * means every one), newest `effective_from` first. A report keeps the
 * template it was given; this only runs when a report is created.
 */
final class TemplateSelector
{
    public function select(string $reportType, string $periodStart, string $periodEnd, CdaRegulatoryProfile $profile): ?CdaReportTemplate
    {
        return CdaReportTemplate::query()
            ->where('report_type', $reportType)
            ->where('status', CdaReportTemplate::STATUS_ACTIVE)
            ->whereDate('effective_from', '<=', $periodEnd)
            ->where(fn (Builder $q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $periodStart))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get()
            ->first(fn (CdaReportTemplate $template) => self::covers($template->cooperative_types, $profile->cooperative_type)
                && self::covers($template->asset_classes, $profile->asset_class));
    }

    /** @param  list<string>|null  $allowed */
    private static function covers(?array $allowed, ?string $value): bool
    {
        return $allowed === null || $allowed === [] || ($value !== null && in_array($value, $allowed, true));
    }
}
