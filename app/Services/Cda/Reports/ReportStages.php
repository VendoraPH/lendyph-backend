<?php

namespace App\Services\Cda\Reports;

use App\Models\CdaReport;
use App\Models\CdaReportValue;
use App\Services\AuditLogService;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The document stages of two reports (design §5, `set_stage`), kept on the
 * report as the reserved value `_stage` (a template field key can never start
 * with "_"). It lives outside the snapshot: the sworn statement is signed and
 * uploaded after it's exported, and the financial statements may still move
 * to audited after finalize, so the screen and exports read the current
 * stage.
 *
 * - Financial statements: draft_fs → for_external_audit → audited. Audited
 *   needs the auditor fields the template names (`meta.auditor_fields`, from
 *   2026.2: name, firm, CPA number, accreditation number and validity, audit
 *   date, opinion and report date), else the auditor's name, accreditation
 *   number, opinion and report date, and the template's required documents;
 *   exports read "Draft Financial Statements" until then.
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

    /** What Audited needs when the template names no `meta.auditor_fields` (the 2026.1 templates). */
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
            $definition = new ReportDefinition($report->template?->definition ?? []);
            $this->assertAuditorFields($report, $definition);

            foreach ($definition->documents() as $document) {
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

    /**
     * The auditor fields Audited needs are filled: the template's
     * `meta.auditor_fields`, named by label when one is missing, else (none
     * named, or none that resolves) the four fixed ones.
     */
    private function assertAuditorFields(CdaReport $report, ReportDefinition $definition): void
    {
        $named = (array) ($definition->definition['meta']['auditor_fields'] ?? []);
        $keys = array_values(array_filter(array_map(fn (mixed $reference) => is_string($reference) ? $definition->resolve($reference) : null, $named)));
        $keys = $keys === [] ? self::AUDITOR_FIELDS : $keys;
        $values = CdaReportValue::query()->where('report_id', $report->id)->whereIn('field_key', $keys)->pluck('value', 'field_key');
        $missing = array_values(array_filter($keys, fn (string $key) => ! FieldValue::filled($values[$key] ?? null)));

        if ($missing === []) {
            return;
        }

        if ($keys === self::AUDITOR_FIELDS) {
            throw self::refused('Audited needs the auditor\'s name, accreditation number, opinion and report date first.');
        }

        $labels = array_map(fn (string $key) => self::lower((string) ($definition->field($key)['label'] ?? $key)), $missing);
        $last = array_pop($labels);

        throw self::refused('Audited needs these auditor details first: '.($labels === [] ? $last : implode(', ', $labels).' and '.$last).'.');
    }

    /** A label inside a sentence: lower-case first letter, unless it starts an abbreviation ("CPA number"). */
    private static function lower(string $label): string
    {
        return preg_match('/^[A-Z]{2}/', $label) === 1 ? $label : lcfirst($label);
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
