<?php

namespace App\Http\Controllers\Api\Cic;

use App\Http\Controllers\Api\Cic\Concerns\ReadsCicRecords;
use App\Models\CicCapture;
use App\Models\CicSetting;
use App\Services\Cic\CicAccess;
use App\Services\Cic\CicLayouts;
use App\Services\Cic\Records\CicRecordsReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * The CIC readiness dashboard (prompt §9), built live at the reference date:
 * counts and issue counts per record type, the fields with the most issues,
 * unmapped products and the last capture. Names no member, so it needs only
 * `cic:view`.
 */
class CicReadinessController extends CicController
{
    use ReadsCicRecords;

    public const TOP_ISSUES = 10;

    #[OA\Get(
        path: '/api/cic/readiness',
        summary: 'CIC data readiness',
        description: 'Needs `cic:view` and `branches:view_all`. Built live now at `reference_date` (Y-m-d, default today, not after today). {reference_date, provider_code_set, blocking, record_types: {ID|CI|CN|NE: {records, with_errors, with_warnings, ready, errors, warnings}}, totals: {records, with_errors, with_warnings, ready, errors, warnings}, file_issues: [issue], top_issues: [{record_type, field_id, field_name, classification, code, severity, count, message}], unmapped_products: [{loan_product_id, name, contracts}], last_capture}. No personal data.',
        tags: ['CIC'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Readiness'), new OA\Response(response: 403, description: 'No access'), new OA\Response(response: 422, description: 'Bad reference date')],
    )]
    public function show(Request $request, CicRecordsReader $reader): JsonResponse
    {
        $this->authorizeCic($request, CicAccess::VIEW);
        $filters = $this->recordFilters($request);

        ['set' => $set, 'issues' => $issues] = $reader->live($filters['reference_date']);
        $counts = CicRecordsReader::countsByRecord($issues);

        $types = [];
        $totals = ['records' => 0, 'with_errors' => 0, 'with_warnings' => 0, 'ready' => 0, 'errors' => 0, 'warnings' => 0];

        foreach (self::RECORD_TYPES as $type) {
            $row = ['records' => 0, 'with_errors' => 0, 'with_warnings' => 0, 'ready' => 0, 'errors' => 0, 'warnings' => 0];

            foreach ($set->ofType($type) as $record) {
                $count = $counts["{$type}|{$record->key}"] ?? ['errors' => 0, 'warnings' => 0];
                $row['records']++;
                $row['errors'] += $count['errors'];
                $row['warnings'] += $count['warnings'];
                $row[$count['errors'] > 0 ? 'with_errors' : 'ready']++;

                if ($count['warnings'] > 0) {
                    $row['with_warnings']++;
                }
            }

            $types[$type] = $row;

            foreach ($row as $name => $value) {
                $totals[$name] += $value;
            }
        }

        $fileIssues = array_values(array_filter($issues, fn (array $i) => in_array($i['record_type'], ['HD', 'FT'], true)));

        return response()->json(['success' => true, 'data' => [
            'reference_date' => $filters['reference_date']->toDateString(),
            'provider_code_set' => CicSetting::current()->hasProviderCode(),
            'blocking' => collect($issues)->contains('severity', 'error'),
            'record_types' => $types,
            'totals' => $totals,
            'file_issues' => array_map([CicRecordsReader::class, 'present'], $fileIssues),
            'top_issues' => $this->topIssues($issues),
            'unmapped_products' => CicRecordsReader::unmappedProducts([...$set->ofType('CI'), ...$set->ofType('CN')]),
            'last_capture' => CicCapturesController::summary(
                CicCapture::query()->where('status', CicCapture::STATUS_COMPLETE)->orderByDesc('reference_date')->orderByDesc('id')->first()
            ),
        ]]);
    }

    /**
     * The fields with the most issues, by record type, field and code.
     *
     * @param  list<array<string, mixed>>  $issues
     * @return list<array<string, mixed>>
     */
    private function topIssues(array $issues): array
    {
        $groups = [];

        foreach ($issues as $issue) {
            if (in_array($issue['record_type'], ['HD', 'FT'], true)) {
                continue;
            }

            $key = "{$issue['record_type']}|{$issue['field_id']}|{$issue['code']}";
            $groups[$key] ??= ['issue' => $issue, 'count' => 0];
            $groups[$key]['count']++;
        }

        usort($groups, fn (array $a, array $b) => [$a['issue']['severity'] === 'error' ? 0 : 1, -$a['count']] <=> [$b['issue']['severity'] === 'error' ? 0 : 1, -$b['count']]);

        return array_map(function (array $group) {
            $issue = $group['issue'];
            $field = $issue['field_id'] === null ? null : CicLayouts::field($issue['field_id']);

            return [
                'record_type' => $issue['record_type'],
                'field_id' => $issue['field_id'],
                'field_name' => $field['name'] ?? null,
                'classification' => $field['classification'] ?? null,
                'code' => $issue['code'],
                'severity' => $issue['severity'],
                'count' => $group['count'],
                'message' => $issue['message'],
            ];
        }, array_slice($groups, 0, self::TOP_ISSUES));
    }
}
