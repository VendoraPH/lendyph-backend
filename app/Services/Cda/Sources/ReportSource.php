<?php

namespace App\Services\Cda\Sources;

use App\Models\CdaReport;
use App\Services\Cda\Reports\ReportDefinition;
use App\Services\Cda\ReportTypes;

/**
 * A field of another report in the same period (design §2,
 * `report.field {type, part, field}`): its finalized snapshot's value once
 * that report is finalized or later, otherwise its stored value, which is
 * provisional. The other report's stored values are read, never refreshed,
 * so two reports reading each other can't loop. Sources run unscoped: the
 * figure is shown on the reading report whatever its type's restriction.
 *
 * Decided (C2 re-review, M-A): the financial statement totals CAPR carries
 * (total assets, liabilities, equity, net surplus) aren't sensitive; they
 * are part of CAPR, which every CDA user files and sees. The audit
 * restriction covers the audited statements' own content (auditor details,
 * notes, documents, the full statements), not those totals. An issue
 * comparing against a restricted report still never shows its figure.
 */
final class ReportSource implements CdaSource
{
    public function resolve(string $key, array $params, SourceContext $context): SourceResult
    {
        if ($key !== 'report.field') {
            throw SourceUnavailable::unknownKey($key, 'report');
        }

        $type = (string) $params['type'];
        $part = (string) $params['part'];
        $field = (string) $params['field'];
        $report = CdaReport::query()->with(['template', 'currentSnapshot'])
            ->where('period_id', $context->period->id)->where('report_type', $type)->where('part', $part)->first();

        $definition = $report?->template === null ? null : new ReportDefinition($report->template->definition);
        $fieldLabel = $definition?->field($field)['label'] ?? $field;
        $label = ReportTypes::title($type).($part === ReportTypes::ANNUAL ? '' : ' ('.ReportTypes::PART_TITLES[$part].')').' · '.$fieldLabel;

        if ($report === null) {
            return new SourceResult(null, $label, 'any', null, true, ['note' => 'That report has not been opened for this period.']);
        }

        if ($report->isLocked() && $report->currentSnapshot !== null) {
            $payload = json_decode($report->currentSnapshot->payload, true);

            return new SourceResult($payload['values'][$field]['value'] ?? null, $label, 'any', null, false, ['report_id' => $report->id, 'snapshot_version' => $report->currentSnapshot->version]);
        }

        $value = $report->values()->where('field_key', $field)->first()?->value;

        return new SourceResult($value, $label, 'any', null, true, ['report_id' => $report->id, 'status' => $report->status]);
    }
}
