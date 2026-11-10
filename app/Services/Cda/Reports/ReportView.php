<?php

namespace App\Services\Cda\Reports;

use App\Http\Resources\Cda\CdaPresenter;
use App\Models\AuditLog;
use App\Models\CdaReport;
use App\Models\CdaReportSnapshot;
use App\Models\CdaReportValue;
use App\Models\User;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Sources\SourceCatalog;

/**
 * GET /cda/reports/{id} (design §3): the report's sections and fields as the
 * template lays them out, each field with its value, where it came from
 * (`source` label, `source_key`, the source's own figure), whether it's
 * provisional, overridden or editable; the issues, readiness and its
 * breakdown, the history, the snapshot, the stage and the actions the caller
 * can take now.
 *
 * A finalized or later report reads entirely from its snapshot, so a later
 * member, Branding or profile change never shows on it.
 */
final class ReportView
{
    public const HISTORY_ACTIONS = ['cda_workflow', 'cda_report_saved', 'cda_report_refreshed'];

    public function __construct(
        private readonly ReportEngine $engine,
        private readonly CdaAccess $access,
    ) {}

    /** @return array<string, mixed> */
    public function report(CdaReport $report, User $viewer): array
    {
        $report->loadMissing(['template', 'period']);
        $definition = $this->engine->definition($report);
        $snapshot = ReportWorkflow::snapshotOf($report);
        $payload = $report->isLocked() && $snapshot !== null ? json_decode($snapshot->payload, true) : null;
        $rows = $payload === null ? $this->rows($report) : $payload['values'];
        $issues = $payload === null ? ($report->issues ?? []) : $payload['issues'];
        $editable = $report->status === CdaReport::STATUS_DRAFT && $report->applicable;

        $sections = [];
        $provisional = false;

        foreach ($definition->sections() as $section) {
            $sectionKey = (string) $section['key'];

            if (($section['kind'] ?? 'fields') === 'table') {
                $field = $definition->field($sectionKey);
                $row = $rows[$sectionKey] ?? [];
                $info = is_array($row['source_value'] ?? null) ? $row['source_value'] : [];
                $provisional = $provisional || (bool) ($info['provisional'] ?? false);
                $sections[] = [
                    'kind' => 'table',
                    'key' => $sectionKey,
                    'title' => (string) $section['title'],
                    'description' => $section['description'] ?? null,
                    'columns' => array_values((array) ($section['columns'] ?? [])),
                    'rows' => is_array($row['value'] ?? null) ? array_values($row['value']) : [],
                    'source' => $row['source_label'] ?? null,
                    'source_key' => $field['source']['key'] ?? null,
                    'source_error' => $info['error'] ?? null,
                    'provisional' => (bool) ($info['provisional'] ?? false),
                    'editable' => $editable && $definition->isManual($field),
                    'remarks' => $row['remarks'] ?? null,
                ];

                continue;
            }

            $fields = [];

            foreach ((array) ($section['fields'] ?? []) as $spec) {
                $field = $definition->field($sectionKey.'.'.$spec['key']);
                $presented = $this->field($field, $rows[$field['full_key']] ?? [], $definition, $editable);
                $provisional = $provisional || $presented['provisional'];
                $fields[] = $presented;
            }

            $sections[] = [
                'kind' => 'fields',
                'key' => $sectionKey,
                'title' => (string) $section['title'],
                'description' => $section['description'] ?? null,
                'fields' => $fields,
            ];
        }

        $base = CdaPresenter::reportRow($report, $report->period, $viewer);

        return [
            ...$base,
            'type' => $report->report_type,
            'display_status' => self::displayStatus($report),
            'stage' => $payload['stage'] ?? ReportStages::current($report),
            'locked' => $report->isLocked(),
            'stale' => $report->stale_at !== null && ($report->refreshed_at === null || $report->stale_at->gt($report->refreshed_at)),
            'provisional' => $provisional,
            'readiness' => (int) ($payload['readiness'] ?? $report->readiness),
            'readiness_label' => Readiness::LABEL,
            'readiness_breakdown' => $payload['readiness_breakdown'] ?? $report->readiness_breakdown,
            'critical_count' => count(array_filter($issues, fn (array $issue) => $issue['severity'] === 'critical')),
            'warning_count' => count(array_filter($issues, fn (array $issue) => $issue['severity'] === 'warning')),
            'issues' => array_values($issues),
            'sections' => $sections,
            'snapshot' => $snapshot === null ? null : self::snapshotSummary($snapshot),
            'snapshot_checksum' => $snapshot?->checksum,
            'finalized_at' => $report->finalized_at,
            'refreshed_at' => $report->refreshed_at,
            'validated_at' => $report->validated_at,
            'updated_at' => $report->updated_at,
            'submitted_for_review_by' => CdaPresenter::user($report->submitted_for_review_by === null ? null : User::query()->find($report->submitted_for_review_by)),
            'submitted_for_review_at' => $report->submitted_for_review_at,
            'approved_by' => CdaPresenter::user($report->approved_by === null ? null : User::query()->find($report->approved_by)),
            'approved_at' => $report->approved_at,
            'finalized_by' => CdaPresenter::user($report->finalized_by === null ? null : User::query()->find($report->finalized_by)),
            'available_actions' => ReportWorkflow::available($report, $viewer, $this->access),
            'history' => $this->history($report),
        ];
    }

    /**
     * The dashboard's and calendar's view of a status: not applicable, not
     * started, returned and validation failed are display words only.
     */
    public static function displayStatus(CdaReport $report): string
    {
        if (! $report->applicable) {
            return 'not_applicable';
        }

        if ($report->status !== CdaReport::STATUS_DRAFT) {
            return $report->status;
        }

        return match (true) {
            $report->sub_status === CdaReport::SUB_STATUS_RETURNED => 'returned',
            $report->refreshed_at === null && $report->validated_at === null => 'not_started',
            $report->critical_count > 0 => 'validation_failed',
            default => 'draft',
        };
    }

    /** @return array<string, mixed> */
    public static function snapshotSummary(CdaReportSnapshot $snapshot): array
    {
        return [
            'id' => (int) $snapshot->id,
            'version' => (int) $snapshot->version,
            'checksum' => $snapshot->checksum,
            'finalized_at' => $snapshot->finalized_at,
            'finalized_by' => CdaPresenter::user($snapshot->finalized_by === null ? null : User::query()->find($snapshot->finalized_by)),
            'superseded_at' => $snapshot->superseded_at,
            'reopened_by' => CdaPresenter::user($snapshot->reopened_by === null ? null : User::query()->find($snapshot->reopened_by)),
            'reopen_reason' => $snapshot->reopen_reason,
        ];
    }

    /**
     * Every transition, save and refresh that changed figures, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function history(CdaReport $report): array
    {
        return AuditLog::query()->with('user')
            ->where('auditable_type', CdaReport::class)->where('auditable_id', $report->id)
            ->whereIn('action', self::HISTORY_ACTIONS)
            ->orderByDesc('id')->limit(200)->get()
            ->map(function (AuditLog $row): array {
                $user = CdaPresenter::user($row->user);
                $new = $row->new_values ?? [];
                $old = $row->old_values ?? [];

                return [
                    'id' => (int) $row->id,
                    'action' => match ($row->action) {
                        'cda_report_saved' => 'save',
                        'cda_report_refreshed' => 'refresh',
                        default => (string) ($new['action'] ?? 'update'),
                    },
                    'from_status' => $old['status'] ?? null,
                    'to_status' => $new['status'] ?? null,
                    'user' => $user,
                    'user_name' => $user['name'] ?? 'System',
                    'reason' => $new['reason'] ?? null,
                    'description' => $row->description,
                    'created_at' => $row->created_at,
                ];
            })->all();
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(CdaReport $report): array
    {
        return CdaReportValue::query()->where('report_id', $report->id)->get()
            ->mapWithKeys(fn (CdaReportValue $row) => [$row->field_key => [
                'value' => $row->value,
                'source_key' => $row->source_key,
                'source_label' => $row->source_label,
                'source_value' => $row->source_value,
                'is_override' => (bool) $row->is_override,
                'override_reason' => $row->override_reason,
                'remarks' => $row->remarks,
                'evidence' => $row->evidence,
            ]])->all();
    }

    /** @return array<string, mixed> */
    private function field(array $field, array $row, ReportDefinition $definition, bool $editable): array
    {
        $info = is_array($row['source_value'] ?? null) ? $row['source_value'] : [];
        $sourceKey = $field['source']['key'] ?? null;
        $isCalc = isset($field['calc']);

        return [
            'key' => (string) $field['key'],
            'field_key' => $field['full_key'],
            'section_key' => $field['section'],
            'label' => ReportValidator::label($field),
            'type' => (string) $field['type'],
            'value' => $row['value'] ?? null,
            'source' => $isCalc ? ReportEngine::CALCULATED : ($sourceKey === null ? null : ($row['source_label'] ?? $info['label'] ?? null)),
            'source_key' => $sourceKey,
            'source_value' => $sourceKey === null ? null : ($info['value'] ?? null),
            'source_error' => $info['error'] ?? null,
            'as_of' => $info['as_of'] ?? null,
            'provisional' => (bool) ($info['provisional'] ?? false),
            'note' => $info['meta']['note'] ?? null,
            'calculated' => $isCalc,
            'overridden' => (bool) ($row['is_override'] ?? false),
            'override_reason' => $row['override_reason'] ?? null,
            'editable' => $editable && $definition->isManual($field),
            'overridable' => $editable && $sourceKey !== null && ($field['overridable'] ?? false) === true && SourceCatalog::overridable($sourceKey),
            'required' => (bool) ($field['required'] ?? false),
            'options' => $field['options'] ?? null,
            'decimals' => $field['decimals'] ?? null,
            'help' => $field['help'] ?? null,
            'remarks' => $row['remarks'] ?? null,
            'evidence' => $row['evidence'] ?? null,
        ];
    }
}
