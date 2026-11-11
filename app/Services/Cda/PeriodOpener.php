<?php

namespace App\Services\Cda;

use App\Models\CdaRegulatoryProfile;
use App\Models\CdaReport;
use App\Models\CdaReportingPeriod;
use App\Models\CdaSetting;
use App\Models\User;
use App\Services\Cda\Reports\ReportEngine;
use App\Services\LoanWriteTransaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Opens a reporting period (CDA design §3): the calendar year, its annual
 * deadline, and one report instance per type and part (H1 and H2 for
 * mediation), each with the active template selected for it, its own
 * default deadline and whether it applies. Eleven rows; ATIR and ABR are
 * kept with `applicable: false` without a valid CTE.
 *
 * One period per year: a second answers 409, decided by the unique index.
 *
 * When staff open a year (open()), each applicable report is refreshed once
 * the period is committed (ReportEngine::refresh()), so the new year shows
 * its figures, readiness and issues at once (CAPR's unclosed-year Critical
 * included) instead of 0% "not started". A report whose refresh fails is
 * logged and left for the `refresh` action or the nightly run; the period
 * stays open. ensure(), the year-end capture's fallback, opens without
 * refreshing: the nightly `cda:revalidate` fills those reports.
 */
final class PeriodOpener
{
    public const DUPLICATE = 'A CDA reporting period for this year already exists.';

    public function __construct(
        private readonly Deadlines $deadlines,
        private readonly TemplateSelector $templates,
        private readonly Applicability $applicability,
        private readonly ReportEngine $engine,
    ) {}

    /** @throws HttpResponseException 409 when the year is open already */
    public function open(int $year, ?User $user): CdaReportingPeriod
    {
        if (CdaReportingPeriod::query()->where('year', $year)->exists()) {
            throw LoanWriteTransaction::conflict(self::DUPLICATE);
        }

        try {
            $period = DB::transaction(fn () => $this->create($year, $user));
        } catch (UniqueConstraintViolationException) {
            throw LoanWriteTransaction::conflict(self::DUPLICATE);
        }

        $this->refreshReports($period, $user);

        return $period;
    }

    /** The year's period, opened (as the system) when there is none. */
    public function ensure(int $year): CdaReportingPeriod
    {
        $existing = CdaReportingPeriod::query()->where('year', $year)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(fn () => $this->create($year, null));
        } catch (UniqueConstraintViolationException) {
            return CdaReportingPeriod::query()->where('year', $year)->firstOrFail();
        }
    }

    /** Fill and validate each applicable report of a period just opened. */
    private function refreshReports(CdaReportingPeriod $period, ?User $user): void
    {
        $reports = CdaReport::query()->where('period_id', $period->id)->where('applicable', true)->orderBy('id')->get();

        foreach ($reports as $report) {
            try {
                $this->engine->refresh($report, $user);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    private function create(int $year, ?User $user): CdaReportingPeriod
    {
        $settings = CdaSetting::current();
        $profile = CdaRegulatoryProfile::current();
        $start = sprintf('%04d-01-01', $year);
        $end = sprintf('%04d-12-31', $year);

        $period = CdaReportingPeriod::query()->create([
            'year' => $year,
            'period_start' => $start,
            'period_end' => $end,
            'default_deadline' => $this->deadlines->annual($year, $settings),
            'created_by' => $user?->id,
        ]);

        foreach (ReportTypes::ALL as $type) {
            $template = $this->templates->select($type, $start, $end, $profile);
            [$applicable, $reason] = $this->applicability->for($type, $template, $start, $profile);

            foreach (ReportTypes::parts($type) as $part) {
                CdaReport::query()->create([
                    'period_id' => $period->id,
                    'report_type' => $type,
                    'part' => $part,
                    'template_id' => $template?->id,
                    'status' => CdaReport::STATUS_DRAFT,
                    'applicable' => $applicable,
                    'not_applicable_reason' => $reason,
                    'default_due_on' => $this->deadlines->forInstance($year, $type, $part, $settings, $template?->definition),
                ]);
            }
        }

        return $period;
    }
}
