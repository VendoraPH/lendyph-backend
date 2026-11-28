<?php

namespace App\Services\Cic\Submissions;

use App\Services\Cic\CicLayouts;

/**
 * A validation result as the file screens show it: counts, and the top issues
 * grouped by field, never a member's values or the issues' own messages
 * (which may quote one). Record keys aren't listed either: the records screens
 * show each record's issues to those who may see them.
 */
final class CicIssueSummary
{
    public const TOP = 10;

    /**
     * @param  list<array{record_type: string, record_key: string, field_id: string|null, severity: string, code: string, message: string}>  $issues
     * @return array{errors: int, warnings: int, top_issues: list<array{record_type: string, field_id: string|null, field_name: string|null, severity: string, code: string, count: int}>}
     */
    public static function of(array $issues): array
    {
        $groups = [];
        $errors = 0;
        $warnings = 0;

        foreach ($issues as $issue) {
            $severity = $issue['severity'] === 'error' ? 'error' : 'warning';
            $severity === 'error' ? $errors++ : $warnings++;

            $key = implode('|', [$severity, $issue['record_type'], $issue['field_id'] ?? '', $issue['code']]);
            $groups[$key] ??= [
                'record_type' => (string) $issue['record_type'],
                'field_id' => $issue['field_id'],
                'field_name' => $issue['field_id'] === null ? null : (CicLayouts::field($issue['field_id'])['name'] ?? null),
                'severity' => $severity,
                'code' => (string) $issue['code'],
                'count' => 0,
            ];
            $groups[$key]['count']++;
        }

        $top = array_values($groups);
        usort($top, fn (array $a, array $b) => [$a['severity'] === 'error' ? 0 : 1, -$a['count'], (string) $a['field_id'], $a['code']]
            <=> [$b['severity'] === 'error' ? 0 : 1, -$b['count'], (string) $b['field_id'], $b['code']]);

        return ['errors' => $errors, 'warnings' => $warnings, 'top_issues' => array_slice($top, 0, self::TOP)];
    }
}
