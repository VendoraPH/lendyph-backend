<?php

namespace App\Services\Cda\Exports;

use App\Models\BrandingSetting;
use App\Models\CdaRegulatoryProfile;
use App\Models\CdaReport;
use App\Models\CdaReportSnapshot;
use App\Models\CdaReportTemplate;
use App\Models\CdaReportValue;
use App\Models\User;
use App\Services\Cda\ReportTypes;
use Carbon\CarbonImmutable;

/**
 * Builds what an export shows (CDA design §9).
 *
 * - A draft, for-approval or approved report renders its stored values live,
 *   with the cooperative's name from Settings › Branding and the current CDA
 *   profile, and carries the DRAFT mark.
 * - A finalized, submitted, accepted or for-correction report renders from
 *   its current snapshot only (SnapshotReader): its values, frozen profile
 *   and template. A later member or Branding change never shows.
 *
 * Field keys are "section.field"; a table section's rows are stored under the
 * section's key.
 */
final class ExportContentBuilder
{
    public const TIMEZONE = 'Asia/Manila';

    private const STATUS_LABELS = [
        CdaReport::STATUS_DRAFT => 'Draft',
        CdaReport::STATUS_FOR_REVIEW => 'For Approval',
        CdaReport::STATUS_APPROVED => 'Approved',
        CdaReport::STATUS_FINALIZED => 'Finalized',
        CdaReport::STATUS_SUBMITTED => 'Submitted',
        CdaReport::STATUS_ACCEPTED => 'Accepted',
        CdaReport::STATUS_FOR_CORRECTION => 'For Correction',
    ];

    public function build(CdaReport $report, User $user): ExportContent
    {
        $report->loadMissing(['period', 'template']);
        $finalized = $report->isLocked();
        $snapshot = $finalized ? CdaReportSnapshot::query()->find($report->current_snapshot_id) : null;

        abort_if($finalized && $snapshot === null, 409, 'This report is finalized but its snapshot is missing, so it can\'t be exported. Reopen it and finalize it again.');

        if ($snapshot !== null) {
            $payload = SnapshotReader::payload($snapshot);
            $template = CdaReportTemplate::query()->find($snapshot->template_id) ?? $report->template;
            $definition = SnapshotReader::definition($payload) ?? (array) ($template?->definition ?? []);
            $values = SnapshotReader::values($payload);
            $profile = SnapshotReader::profile($payload);
            $coopName = SnapshotReader::pulled($payload, 'name');
            $coopAddress = SnapshotReader::pulled($payload, 'address');
            $templateVersion = (string) ($payload['template']['version'] ?? $template?->version ?? '—');
        } else {
            $template = $report->template;
            $definition = (array) ($template?->definition ?? []);
            $values = CdaReportValue::query()->where('report_id', $report->id)->get(['field_key', 'value'])
                ->mapWithKeys(fn (CdaReportValue $row) => [$row->field_key => $row->value])->all();
            $profile = $this->liveProfile();
            $branding = BrandingSetting::query()->orderBy('id')->first();
            $coopName = $branding?->organization_name;
            $coopAddress = $branding?->organization_address;
            $templateVersion = (string) ($template?->version ?? '—');
        }

        $period = $report->period;
        $periodLabel = 'CY'.$period->year.($report->part === ReportTypes::ANNUAL ? '' : ' · '.(ReportTypes::PART_TITLES[$report->part] ?? $report->part));

        return new ExportContent(
            reportId: (int) $report->id,
            reportType: $report->report_type,
            title: ReportTypes::title($report->report_type),
            periodLabel: $periodLabel,
            coopName: filled($coopName) ? (string) $coopName : ValueFormatter::EMPTY,
            coopAddress: filled($coopAddress) ? (string) $coopAddress : null,
            registrationNo: filled($profile['cda_registration_no'] ?? null) ? (string) $profile['cda_registration_no'] : null,
            statusLabel: self::STATUS_LABELS[$report->status] ?? $report->status,
            isDraft: $snapshot === null,
            snapshotId: $snapshot?->id,
            snapshotVersion: $snapshot === null ? null : (int) $snapshot->version,
            checksum: $snapshot?->checksum,
            templateId: $template?->id,
            templateVersion: $templateVersion,
            excelTemplateDocumentId: $template?->excel_template_document_id,
            generatedAt: CarbonImmutable::now(self::TIMEZONE)->format('M j, Y, g:i A'),
            generatedBy: $this->name($user),
            orientation: ($definition['export']['pdf']['orientation'] ?? 'portrait') === 'landscape' ? 'landscape' : 'portrait',
            sections: $this->sections((array) ($definition['sections'] ?? []), $values),
            values: $values,
            profile: $profile,
            excel: (array) ($definition['export']['excel'] ?? []),
        );
    }

    /** @return array<string, mixed> */
    private function liveProfile(): array
    {
        $profile = CdaRegulatoryProfile::current();
        $stored = [];

        foreach (CdaRegulatoryProfile::FIELDS as $field) {
            $value = $profile->{$field};
            $stored[$field] = in_array($field, CdaRegulatoryProfile::DATE_FIELDS, true) ? $value?->toDateString() : $value;
        }

        return $stored;
    }

    private function name(User $user): string
    {
        $name = trim((string) $user->full_name);

        return $name !== '' ? $name : (string) $user->username;
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @param  array<string, mixed>  $values
     * @return list<array<string, mixed>>
     */
    private function sections(array $sections, array $values): array
    {
        $out = [];

        foreach ($sections as $section) {
            $key = (string) ($section['key'] ?? '');

            if (($section['kind'] ?? 'fields') === 'table') {
                $out[] = $this->table($key, (string) ($section['title'] ?? $key), (array) ($section['columns'] ?? []), $values[$key] ?? []);

                continue;
            }

            $fields = [];

            foreach ((array) ($section['fields'] ?? []) as $field) {
                $fieldKey = $key.'.'.$field['key'];
                $raw = $values[$fieldKey] ?? null;

                if (($field['type'] ?? null) === 'table') {
                    $out[] = $this->table($fieldKey, (string) ($field['label'] ?? $field['key']), (array) ($field['columns'] ?? []), $raw ?? []);

                    continue;
                }

                $fields[] = [
                    'key' => $fieldKey,
                    'label' => (string) ($field['label'] ?? $field['key']),
                    'type' => (string) ($field['type'] ?? 'text'),
                    'display' => ValueFormatter::display($raw, (string) ($field['type'] ?? 'text'), $field),
                    'raw' => $raw,
                ];
            }

            $out[] = ['key' => $key, 'title' => (string) ($section['title'] ?? $key), 'kind' => 'fields', 'fields' => $fields, 'columns' => [], 'rows' => [], 'raw_rows' => []];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @return array<string, mixed>
     */
    private function table(string $key, string $title, array $columns, mixed $rows): array
    {
        $rows = is_array($rows) && array_is_list($rows) ? $rows : [];
        $columns = array_map(fn (array $column): array => [
            'key' => (string) $column['key'],
            'label' => (string) ($column['label'] ?? $column['key']),
            'format' => (string) ($column['format'] ?? 'text'),
        ], $columns);

        return [
            'key' => $key,
            'title' => $title,
            'kind' => 'table',
            'fields' => [],
            'columns' => $columns,
            'rows' => array_map(fn ($row) => array_map(
                fn (array $column) => ValueFormatter::display(is_array($row) ? ($row[$column['key']] ?? null) : null, $column['format']),
                $columns,
            ), $rows),
            'raw_rows' => array_map(fn ($row) => array_map(fn (array $column) => is_array($row) ? ($row[$column['key']] ?? null) : null, $columns), $rows),
        ];
    }
}
