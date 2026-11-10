<?php

namespace App\Services\Cda\Reports;

use App\Models\CdaReport;
use App\Services\Cda\ReportTypes;

/**
 * What a CDA report's save and refresh audit rows may hold (C2 review H1,
 * L8). Every row names the fields that changed (`fields`, `count`).
 *
 * - A restricted report (mediation, audit, tax) never puts a value in an
 *   audit row: keys and counts only, and whether a field was overridden,
 *   never the override's reason, remarks or evidence. Mediation text and
 *   tax or audit figures stay on the restricted screens.
 * - Any other report keeps each changed value, except a table, which reads
 *   as its number of rows (`{"rows": 2}`), since its rows can carry
 *   people's names.
 * - A save also records each override's change (`is_override`, and its
 *   reason where the report isn't restricted) and which fields' remarks or
 *   evidence changed, by key only.
 *
 * Decided (C2 re-review, M-A): "restricted" is the report type's own
 * restriction. CAPR isn't restricted, so the financial statement totals it
 * carries (total assets, liabilities, equity, net surplus) are audited like
 * any CAPR figure: every CDA user files and sees them. The audit
 * restriction covers the audited statements' own content.
 */
final class AuditValues
{
    public static function restricted(CdaReport $report): bool
    {
        return ReportTypes::restriction($report->report_type) !== null;
    }

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes  full key => [old, new]
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} old values, new values
     */
    public static function forRefresh(CdaReport $report, ReportDefinition $definition, array $changes): array
    {
        $keys = array_keys($changes);
        $summary = ['fields' => $keys, 'count' => count($keys)];

        if (self::restricted($report)) {
            return [$summary, $summary];
        }

        return [
            [...$summary, 'values' => self::values($definition, array_map(fn (array $pair) => $pair[0], $changes))],
            [...$summary, 'values' => self::values($definition, array_map(fn (array $pair) => $pair[1], $changes))],
        ];
    }

    /**
     * @param  array<string, array{value: array{0: mixed, 1: mixed}|null, override: array{0: array{is_override: bool, override_reason: string|null}, 1: array{is_override: bool, override_reason: string|null}}|null, remarks: bool, evidence: bool}>  $entries
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function forSave(CdaReport $report, ReportDefinition $definition, array $entries): array
    {
        $restricted = self::restricted($report);
        $keys = array_keys($entries);
        $old = ['fields' => $keys, 'count' => count($keys)];
        $new = $old;
        $valueChanges = array_filter($entries, fn (array $entry) => $entry['value'] !== null);
        $overrideChanges = array_filter($entries, fn (array $entry) => $entry['override'] !== null);

        if (! $restricted && $valueChanges !== []) {
            $old['values'] = self::values($definition, array_map(fn (array $entry) => $entry['value'][0], $valueChanges));
            $new['values'] = self::values($definition, array_map(fn (array $entry) => $entry['value'][1], $valueChanges));
        }

        if ($overrideChanges !== []) {
            $shape = fn (array $state) => $restricted ? ['is_override' => $state['is_override']] : $state;
            $old['overrides'] = array_map(fn (array $entry) => $shape($entry['override'][0]), $overrideChanges);
            $new['overrides'] = array_map(fn (array $entry) => $shape($entry['override'][1]), $overrideChanges);
        }

        $new['remarks_changed'] = array_keys(array_filter($entries, fn (array $entry) => $entry['remarks']));
        $new['evidence_changed'] = array_keys(array_filter($entries, fn (array $entry) => $entry['evidence']));

        return [$old, $new];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function values(ReportDefinition $definition, array $values): array
    {
        foreach ($values as $key => $value) {
            if (($definition->field($key)['type'] ?? null) === 'table') {
                $values[$key] = ['rows' => is_array($value) ? count($value) : 0];
            }
        }

        return $values;
    }
}
