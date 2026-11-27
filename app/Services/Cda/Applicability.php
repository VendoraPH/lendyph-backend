<?php

namespace App\Services\Cda;

use App\Models\CdaRegulatoryProfile;
use App\Models\CdaReport;
use App\Models\CdaReportTemplate;

/**
 * Whether a report applies to the cooperative (CDA design §6): ATIR and ABR,
 * and any template whose `applies.requires_cte` is true, apply only when a
 * Certificate of Tax Exemption covers the period (`cte_status` valid, and no
 * expiry or one on or after the period start). Otherwise the report is kept
 * with `applicable: false` and the reason in words.
 */
final class Applicability
{
    /** @return array{0: bool, 1: string|null} applicable, and why not */
    public function for(string $reportType, ?CdaReportTemplate $template, string $periodStart, CdaRegulatoryProfile $profile): array
    {
        $needsCte = ReportTypes::requiresCte($reportType) || (bool) ($template?->definition['applies']['requires_cte'] ?? false);

        if ($needsCte && ! $profile->cteCovers($periodStart)) {
            return [false, ReportTypes::NOT_APPLICABLE_WITHOUT_CTE];
        }

        return [true, null];
    }

    /**
     * After the profile's CTE changes: recompute applicability on reports
     * still in draft. A report past draft keeps what it was.
     */
    public function syncDrafts(CdaRegulatoryProfile $profile): void
    {
        CdaReport::query()
            ->where('status', CdaReport::STATUS_DRAFT)
            ->with(['period', 'template'])
            ->get()
            ->each(function (CdaReport $report) use ($profile): void {
                [$applicable, $reason] = $this->for($report->report_type, $report->template, $report->period->period_start->toDateString(), $profile);

                if ($report->applicable !== $applicable || $report->not_applicable_reason !== $reason) {
                    $report->forceFill(['applicable' => $applicable, 'not_applicable_reason' => $reason])->save();
                }
            });
    }
}
