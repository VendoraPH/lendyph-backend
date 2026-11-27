<?php

namespace App\Http\Resources\Cda;

use App\Models\CdaReport;
use App\Models\CdaReportingPeriod;
use App\Models\CdaReportTemplate;
use App\Models\CdaSetting;
use App\Models\User;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Deadlines;
use App\Services\Cda\ReportTypes;

/**
 * How the CDA endpoints present their rows (CDA design §3): dates as
 * YYYY-MM-DD, users as `{id, name}` (null for the system), money as
 * two-decimal strings, deadlines with their effective date and Manila
 * `days_remaining`. C2–C4 reuse these so a report row reads the same on
 * every screen.
 */
final class CdaPresenter
{
    /** Centavos as the API sends money: a 2-decimal string, "1500.50", "-12.05". */
    public static function money(int $centavos): string
    {
        return sprintf('%s%d.%02d', $centavos < 0 ? '-' : '', intdiv(abs($centavos), 100), abs($centavos) % 100);
    }

    /** @return array{id: int, name: string}|null */
    public static function user(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $name = trim((string) $user->full_name);

        return ['id' => (int) $user->id, 'name' => $name !== '' ? $name : (string) $user->username];
    }

    /**
     * A period, with its reports when given.
     *
     * @param  iterable<CdaReport>|null  $reports
     * @return array<string, mixed>
     */
    public static function period(CdaReportingPeriod $period, ?User $viewer = null, ?iterable $reports = null): array
    {
        $effective = $period->annualDeadline();

        $data = [
            'id' => (int) $period->id,
            'year' => (int) $period->year,
            'period_start' => $period->period_start->toDateString(),
            'period_end' => $period->period_end->toDateString(),
            'default_deadline' => $period->default_deadline->toDateString(),
            'extended_deadline' => $period->extended_deadline?->toDateString(),
            'extension_reference' => $period->extension_reference,
            'effective_deadline' => $effective,
            'days_remaining' => Deadlines::daysRemaining($effective),
            'year_end_captured_at' => $period->year_end_captured_at,
            'created_by' => self::user($period->relationLoaded('creator') ? $period->creator : $period->creator()->first()),
            'created_at' => $period->created_at,
        ];

        if ($reports !== null) {
            $data['reports'] = collect($reports)->map(fn (CdaReport $report) => self::reportRow($report, $period, $viewer))->values()->all();
        }

        return $data;
    }

    /**
     * A report instance as a list or calendar row: its type and part in
     * words, status, whether it applies, whether the viewer is barred from
     * it (`restricted`), its template, and its deadlines.
     *
     * @return array<string, mixed>
     */
    public static function reportRow(CdaReport $report, CdaReportingPeriod $period, ?User $viewer = null): array
    {
        $effective = Deadlines::effective($report, $period);
        $template = $report->relationLoaded('template') ? $report->template : $report->template()->first();

        return [
            'id' => (int) $report->id,
            'period_id' => (int) $report->period_id,
            'year' => (int) $period->year,
            'report_type' => $report->report_type,
            'part' => $report->part,
            'title' => ReportTypes::title($report->report_type),
            'title_part' => ReportTypes::PART_TITLES[$report->part] ?? $report->part,
            'status' => $report->status,
            'sub_status' => $report->sub_status,
            'applicable' => (bool) $report->applicable,
            'not_applicable_reason' => $report->not_applicable_reason,
            'restricted' => $viewer !== null && ! app(CdaAccess::class)->canSeeType($viewer, $report->report_type),
            'template' => $template === null ? null : [
                'id' => (int) $template->id,
                'name' => $template->name,
                'version' => $template->version,
                'verified' => $template->isVerified(),
            ],
            'default_due_on' => $report->default_due_on->toDateString(),
            'due_override_on' => $report->due_override_on?->toDateString(),
            'due_override_reference' => $report->due_override_reference,
            'effective_deadline' => $effective,
            'days_remaining' => Deadlines::daysRemaining($effective),
        ];
    }

    /** @return array<string, mixed> */
    public static function template(CdaReportTemplate $template): array
    {
        $file = $template->relationLoaded('excelTemplate') ? $template->excelTemplate : $template->excelTemplate()->first();

        return [
            'id' => (int) $template->id,
            'report_type' => $template->report_type,
            'name' => $template->name,
            'version' => $template->version,
            'effective_from' => $template->effective_from->toDateString(),
            'effective_until' => $template->effective_until?->toDateString(),
            'cooperative_types' => array_values($template->cooperative_types ?? []),
            'asset_classes' => array_values($template->asset_classes ?? []),
            'status' => $template->status,
            'reason' => $template->reason,
            'verified' => $template->isVerified(),
            'definition' => $template->definition,
            'excel_template' => $file === null ? null : ['document_id' => (int) $file->id, 'file_name' => $file->original_filename],
            'created_by' => self::user($template->relationLoaded('creator') ? $template->creator : $template->creator()->first()),
            'activated_at' => $template->activated_at,
            'created_at' => $template->created_at,
            'updated_at' => $template->updated_at,
        ];
    }

    /** @return array<string, mixed> */
    public static function settings(CdaSetting $settings): array
    {
        return [
            'annual_deadline_days' => (int) $settings->annual_deadline_days,
            'mediation_deadline_days' => (int) $settings->mediation_deadline_days,
            'reminder_days' => array_values(array_map('intval', $settings->reminder_days ?? [])),
            'required_trainings' => (object) ($settings->required_trainings ?? []),
            'asset_class_thresholds' => (object) ($settings->asset_class_thresholds ?? []),
            'updated_by' => self::user($settings->updater()->first()),
            'updated_at' => $settings->updated_at,
        ];
    }
}
