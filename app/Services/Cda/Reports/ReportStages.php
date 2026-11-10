<?php

namespace App\Services\Cda\Reports;

use App\Models\CdaReport;
use App\Models\CdaReportValue;
use App\Services\AuditLogService;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The document stages of two reports (design §5, `set_stage`), kept on the
 * report as the reserved value `_stage` (a template field key can never start
 * with "_"), so the stage travels in the snapshot.
 *
 * - Financial statements: draft_fs → for_external_audit → audited. Audited
 *   needs the auditor's name, accreditation number, opinion and report date,
 *   and the template's required documents; exports read "Draft Financial
 *   Statements" until then.
 * - Sworn statement: generated (set by the first export, markGenerated())
 *   → for_signature → signed → uploaded, which needs a final signed upload.
 *
 * A stage moves forward one step at a time. Each move writes an audit row.
 */
final class ReportStages
{
    public const KEY = '_stage';

    public const STAGES = [
        'financial_statements' => ['draft_fs', 'for_external_audit', 'audited'],
        'sworn_statement' => ['generated', 'for_signature', 'signed', 'uploaded'],
    ];

    public const WORDS = [
        'draft_fs' => 'Draft financial statements',
        'for_external_audit' => 'For external audit',
        'audited' => 'Audited',
        'generated' => 'Generated',
        'for_signature' => 'For signature',
        'signed' => 'Signed',
        'uploaded' => 'Uploaded',
    ];

    private const AUDITOR_FIELDS = ['auditor.auditor_name', 'auditor.auditor_accreditation_no', 'auditor.audit_opinion', 'auditor.audit_report_date'];

    public function __construct(private readonly ReportValidator $validator) {}

    /** The report's stage, or the first stage of its type, or null for a type with none. */
    public static function current(CdaReport $report): ?string
    {
        $stages = self::STAGES[$report->report_type] ?? null;

        if ($stages === null) {
            return null;
        }

        $stored = CdaReportValue::query()->where('report_id', $report->id)->where('field_key', self::KEY)->first()?->value;

        return is_string($stored) ? $stored : ($report->report_type === 'financial_statements' ? $stages[0] : null);
    }

    /** Move the report to the next stage, after its checks. The caller holds the report's lock. */
    public function advance(CdaReport $report, string $stage): void
    {
        $stages = self::STAGES[$report->report_type];
        $current = self::current($report);
        $position = $current === null ? -1 : array_search($current, $stages, true);
        $target = array_search($stage, $stages, true);

        if ($target !== $position + 1) {
            throw self::refused('The stage moves one step at a time: '.($current === null ? 'it starts at '.self::WORDS[$stages[0]] : 'after '.self::WORDS[$current].' comes '.(self::WORDS[$stages[$position + 1]] ?? 'nothing')).'.');
        }

        if ($stage === 'audited') {
            $values = CdaReportValue::query()->where('report_id', $report->id)->whereIn('field_key', self::AUDITOR_FIELDS)->pluck('value', 'field_key');

            if (collect(self::AUDITOR_FIELDS)->contains(fn (string $key) => ! FieldValue::filled($values[$key] ?? null))) {
                throw self::refused('Audited needs the auditor\'s name, accreditation number, opinion and report date first.');
            }

            foreach ((new ReportDefinition($report->template?->definition ?? []))->documents() as $document) {
                if (! $this->validator->documentPresent($report, (string) $document['category'], false)) {
                    throw self::refused('Audited needs '.($document['label'] ?? $document['category']).' uploaded first.');
                }
            }
        }

        if ($stage === 'uploaded' && ! $this->validator->documentPresent($report, 'sworn_statement', true)) {
            throw self::refused('Uploaded needs the final signed sworn statement uploaded first.');
        }

        $this->store($report, $current, $stage);
    }

    /** The sworn statement's first export sets "generated" (called by the exports, C4). */
    public function markGenerated(CdaReport $report): void
    {
        if ($report->report_type === 'sworn_statement' && self::current($report) === null) {
            $this->store($report, null, 'generated');
        }
    }

    private function store(CdaReport $report, ?string $from, string $to): void
    {
        CdaReportValue::query()->updateOrCreate(['report_id' => $report->id, 'field_key' => self::KEY], ['value' => $to, 'source_label' => 'Stage', 'updated_by' => auth()->id()]);

        AuditLogService::log('cda_workflow', $report, ['status' => $report->status, 'stage' => $from], [
            'status' => $report->status,
            'action' => 'set_stage',
            'stage' => $to,
        ], sprintf('%s %d: stage %s → %s.', ReportValidator::reportName($report), $report->period->year, $from === null ? 'none' : self::WORDS[$from], self::WORDS[$to]));
    }

    private static function refused(string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json(['message' => $message, 'errors' => ['stage' => [$message]]], 422));
    }
}
