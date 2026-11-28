<?php

namespace App\Http\Controllers\Api\Cic;

use App\Http\Controllers\Api\Cic\Concerns\ReadsCicRecords;
use App\Models\CicCaptureRecord;
use App\Services\Cic\CicAccess;
use App\Services\Cic\Records\CicRecord;
use App\Services\Cic\Records\CicRecordsReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Every CIC issue, errors first, from a capture or a live build, filtered by
 * severity and record type. An issue names its member only for a caller
 * holding `borrowers:view`.
 */
class CicIssuesController extends CicController
{
    use ReadsCicRecords;

    #[OA\Get(
        path: '/api/cic/issues',
        summary: 'CIC issues',
        description: 'Needs `cic:view` and `branches:view_all`. `severity` (error, warning), `record_type` (ID, CI, CN, NE), `capture_id` (else live at `reference_date`), `page` (25 a page). {data: [{record_type, record_key, field_id, field_name, classification, severity, code, message, member: {name, code} (only with borrowers:view)}], meta: {current_page, last_page, per_page, total, errors, warnings, source, capture_id, reference_date}}. File-level issues have record_type HD or FT and an empty record_key.',
        tags: ['CIC'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The issues'), new OA\Response(response: 403, description: 'No access'), new OA\Response(response: 404, description: 'No such capture'), new OA\Response(response: 422, description: 'Bad filter')],
    )]
    public function index(Request $request, CicRecordsReader $reader): JsonResponse
    {
        $this->authorizeCic($request, CicAccess::VIEW);
        $filters = $this->recordFilters($request);
        $capture = $filters['capture'];
        $set = null;

        if ($capture !== null) {
            $issues = $reader->captureIssues($capture);
        } else {
            ['set' => $set, 'issues' => $issues] = $reader->live($filters['reference_date']);
        }

        $matching = array_values(array_filter($issues, fn (array $i) => ($filters['severity'] === null || $i['severity'] === $filters['severity'])
            && ($filters['record_type'] === null || $i['record_type'] === $filters['record_type'])));
        $pageRows = array_slice($matching, ($filters['page'] - 1) * self::PER_PAGE, self::PER_PAGE);
        $members = $this->seesPersonalData($request) ? $this->membersOf($pageRows, $capture?->id, $set?->all()) : null;

        $data = array_map(fn (array $issue) => [
            ...CicRecordsReader::present($issue),
            ...($members === null ? [] : ['member' => $members["{$issue['record_type']}|{$issue['record_key']}"] ?? null]),
        ], $pageRows);

        return response()->json($this->page($data, count($matching), $filters['page'], [
            'errors' => count(array_filter($matching, fn (array $i) => $i['severity'] === 'error')),
            'warnings' => count(array_filter($matching, fn (array $i) => $i['severity'] === 'warning')),
            'source' => $capture === null ? 'live' : 'capture',
            'capture_id' => $capture?->id,
            'reference_date' => ($capture?->reference_date ?? $filters['reference_date'])->toDateString(),
        ]));
    }

    /**
     * The member of each issue's record, keyed "type|key".
     *
     * @param  list<array<string, mixed>>  $issues
     * @param  list<CicRecord>|null  $live
     * @return array<string, array{name: string, code: string}>
     */
    private function membersOf(array $issues, ?int $captureId, ?array $live): array
    {
        $wanted = [];

        foreach ($issues as $issue) {
            if ($issue['record_key'] !== '') {
                $wanted["{$issue['record_type']}|{$issue['record_key']}"] = [$issue['record_type'], $issue['record_key']];
            }
        }

        if ($wanted === []) {
            return [];
        }

        $borrowers = [];

        if ($live !== null) {
            foreach ($live as $record) {
                if (isset($wanted["{$record->type}|{$record->key}"])) {
                    $borrowers["{$record->type}|{$record->key}"] = $record->borrowerId;
                }
            }
        } else {
            CicCaptureRecord::query()->where('capture_id', $captureId)
                ->where(function ($query) use ($wanted) {
                    foreach ($wanted as [$type, $key]) {
                        $query->orWhere(fn ($q) => $q->where('record_type', $type)->where('record_key', $key));
                    }
                })
                ->with(['subject:id,borrower_id', 'loan:id,borrower_id'])
                ->get(['id', 'record_type', 'record_key', 'cic_subject_id', 'loan_id'])
                ->each(function (CicCaptureRecord $r) use (&$borrowers) {
                    $borrowers["{$r->record_type}|{$r->record_key}"] = $r->subject?->borrower_id ?? $r->loan?->borrower_id;
                });
        }

        $members = CicRecordsReader::members(array_values($borrowers));

        return array_filter(array_map(fn (?int $id) => $id === null ? null : ($members[$id] ?? null), $borrowers));
    }
}
