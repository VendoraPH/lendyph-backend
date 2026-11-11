<?php

namespace App\Services\Cda\Reports;

use App\Http\Resources\Cda\CdaPresenter;
use App\Models\CdaReport;
use App\Models\CdaReportingPeriod;
use App\Models\User;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Deadlines;
use App\Services\Cda\ReportTypes;
use App\Services\Cda\Sources\AccountingSource;
use Illuminate\Support\Collection;

/**
 * The CDA dashboard and calendar (design §3, §10), read from the stored
 * readiness and issues (a GET never refreshes anything).
 *
 * Reports the caller can't open (mediation, audit or tax without its view)
 * are left out entirely: no row, no counts, no readiness, no next deadline
 * and no issues, so the header never counts what the caller can't see.
 *
 * - Readiness: "Lendy CDA Readiness", the mean of the applicable reports.
 * - Counts by display status, applicable reports, critical and warnings.
 * - The next deadline: the nearest one still to come among the reports not
 *   yet filed.
 * - A row per report, and every issue. A locked report's documents needed
 *   at filing are checked live (ReportEngine::issuesNow() and
 *   readinessNow()), in its issues, its readiness and the period's mean;
 *   its other issues and checks stay as finalized.
 * - A finalized report whose accounting year was reopened afterwards raises
 *   a Warning.
 *
 * The calendar is one row per instance, by effective deadline.
 */
final class ReportDashboard
{
    /** Filed or beyond: no longer counts towards a deadline. */
    private const FILED = [CdaReport::STATUS_SUBMITTED, CdaReport::STATUS_ACCEPTED];

    public function __construct(
        private readonly CdaAccess $access,
        private readonly ReportEngine $engine,
    ) {}

    /** @return array<string, mixed> */
    public function dashboard(CdaReportingPeriod $period, User $viewer): array
    {
        $reports = $this->reports($period, $viewer);
        $current = $reports->mapWithKeys(fn (CdaReport $report) => [$report->id => $this->engine->issuesNow($report)]);
        $readiness = $reports->mapWithKeys(fn (CdaReport $report) => [$report->id => $this->engine->readinessNow($report)['readiness']]);
        $applicable = $reports->where('applicable', true);
        $yearClosed = AccountingSource::yearClosed((int) $period->year);
        $rows = [];
        $issues = [];

        foreach ($reports as $report) {
            $rows[] = $this->row($report, $period, $viewer, $current[$report->id], $readiness[$report->id]);

            if (! $report->applicable) {
                continue;
            }

            foreach ($current[$report->id] as $issue) {
                $issues[] = [...$issue, 'report_id' => (int) $report->id, 'report_type' => $report->report_type, 'part' => $report->part];
            }

            if ($report->isLocked() && ! $yearClosed && $this->usedClosedBooks($report)) {
                $issues[] = [
                    'id' => "reopened_year:{$report->id}", 'rule_id' => null, 'severity' => 'warning', 'category' => 'accounting_mismatch',
                    'component' => 'accounting', 'section_key' => null, 'field_key' => null, 'fix_href' => '/accounting', 'stage' => null,
                    'message' => "The {$period->year} accounting year was reopened after ".ReportValidator::reportName($report).' was finalized. Check the figures and reopen the report if they changed.',
                    'report_id' => (int) $report->id, 'report_type' => $report->report_type, 'part' => $report->part,
                ];
            }
        }

        $upcoming = $applicable
            ->reject(fn (CdaReport $report) => in_array($report->status, self::FILED, true))
            ->map(fn (CdaReport $report) => ['report' => $report, 'deadline' => Deadlines::effective($report, $period)])
            ->filter(fn (array $row) => Deadlines::daysRemaining($row['deadline']) >= 0)
            ->sortBy(fn (array $row) => $row['deadline'].sprintf('%010d', $row['report']->id))
            ->first();

        return [
            'period' => CdaPresenter::period($period),
            'readiness' => Readiness::mean($applicable->map(fn (CdaReport $report) => $readiness[$report->id])->values()->all()),
            'readiness_label' => Readiness::LABEL,
            'counts' => [
                'total' => $reports->count(),
                'applicable' => $applicable->count(),
                'by_status' => collect($rows)->countBy('display_status')->all(),
                'critical' => $applicable->sum(fn (CdaReport $report) => self::count($current[$report->id], 'critical')),
                'warnings' => $applicable->sum(fn (CdaReport $report) => self::count($current[$report->id], 'warning')),
                'filed' => $applicable->whereIn('status', self::FILED)->count(),
            ],
            'next_deadline' => $upcoming === null ? null : [
                'report_id' => (int) $upcoming['report']->id,
                'report_type' => $upcoming['report']->report_type,
                'part' => $upcoming['report']->part,
                'title' => ReportTypes::title($upcoming['report']->report_type),
                'title_part' => ReportTypes::PART_TITLES[$upcoming['report']->part],
                'effective_deadline' => $upcoming['deadline'],
                'days_remaining' => Deadlines::daysRemaining($upcoming['deadline']),
            ],
            'reports' => $rows,
            'issues' => $issues,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function calendar(CdaReportingPeriod $period, User $viewer): array
    {
        return $this->reports($period, $viewer)
            ->map(fn (CdaReport $report) => [
                ...CdaPresenter::reportRow($report, $period, $viewer),
                'display_status' => ReportView::displayStatus($report),
                'readiness' => $this->engine->readinessNow($report)['readiness'],
                'filed' => in_array($report->status, self::FILED, true),
            ])
            ->sortBy(fn (array $row) => $row['effective_deadline'].sprintf('%010d', $row['id']))
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $issues  the report's issues as they stand now
     * @param  int  $readiness  and its readiness (ReportEngine::readinessNow())
     * @return array<string, mixed>
     */
    private function row(CdaReport $report, CdaReportingPeriod $period, User $viewer, array $issues, int $readiness): array
    {
        $effective = Deadlines::effective($report, $period);

        return [
            'id' => (int) $report->id,
            'type' => $report->report_type,
            'report_type' => $report->report_type,
            'part' => $report->part,
            'title' => ReportTypes::title($report->report_type),
            'title_part' => ReportTypes::PART_TITLES[$report->part] ?? $report->part,
            'status' => $report->status,
            'display_status' => ReportView::displayStatus($report),
            'sub_status' => $report->sub_status,
            'readiness' => $readiness,
            'critical' => self::count($issues, 'critical'),
            'warnings' => self::count($issues, 'warning'),
            'applicable' => (bool) $report->applicable,
            'restricted' => ! $this->access->canSeeType($viewer, $report->report_type),
            'effective_deadline' => $effective,
            'days_remaining' => Deadlines::daysRemaining($effective),
            'updated_at' => $report->updated_at,
        ];
    }

    /** Whether the finalized snapshot read figures from a closed year. */
    private function usedClosedBooks(CdaReport $report): bool
    {
        $snapshot = ReportWorkflow::snapshotOf($report);

        if ($snapshot === null) {
            return false;
        }

        $values = json_decode($snapshot->payload, true)['values'] ?? [];

        return collect($values)->contains(fn ($row) => ($row['source_value']['meta']['year_closed'] ?? null) === true);
    }

    /** @param  list<array<string, mixed>>  $issues */
    private static function count(array $issues, string $severity): int
    {
        return count(array_filter($issues, fn (array $issue) => $issue['severity'] === $severity));
    }

    /**
     * The period's reports the viewer may open, in type and part order.
     *
     * @return Collection<int, CdaReport>
     */
    private function reports(CdaReportingPeriod $period, User $viewer): Collection
    {
        $order = array_flip(ReportTypes::ALL);

        return $this->access->visibleReports($viewer, $period->reports()->with(['template', 'period'])->get())
            ->sortBy(fn (CdaReport $report) => sprintf('%02d-%d', $order[$report->report_type] ?? 99, array_search($report->part, ReportTypes::PARTS, true)))
            ->values();
    }
}
