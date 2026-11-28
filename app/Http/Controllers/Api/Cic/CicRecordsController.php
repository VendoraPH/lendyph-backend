<?php

namespace App\Http\Controllers\Api\Cic;

use App\Http\Controllers\Api\Cic\Concerns\ReadsCicRecords;
use App\Services\Cic\CicAccess;
use App\Services\Cic\Records\CicRecord;
use App\Services\Cic\Records\CicRecordsReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * The CIC records (subjects, contracts and events) with their issue counts,
 * from a capture (`capture_id`) or built live at `reference_date`. A row
 * names its member only for a caller holding `borrowers:view`; a record's
 * field values need it too.
 */
class CicRecordsController extends CicController
{
    use ReadsCicRecords;

    #[OA\Get(
        path: '/api/cic/records',
        summary: 'CIC records',
        description: 'Needs `cic:view` and `branches:view_all`. `record_type` (ID, CI, CN, NE), `capture_id` (else live at `reference_date`), `page` (25 a page). {data: [{record_type, record_key, errors, warnings, member: {name, code} (only with borrowers:view)}], meta: {current_page, last_page, per_page, total, source: live|capture, capture_id, reference_date}}.',
        tags: ['CIC'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The records'), new OA\Response(response: 403, description: 'No access'), new OA\Response(response: 404, description: 'No such capture'), new OA\Response(response: 422, description: 'Bad filter')],
    )]
    public function index(Request $request, CicRecordsReader $reader): JsonResponse
    {
        $this->authorizeCic($request, CicAccess::VIEW);
        $filters = $this->recordFilters($request);
        $capture = $filters['capture'];
        $page = $filters['page'];

        if ($capture !== null) {
            $counts = CicRecordsReader::countsByRecord($reader->captureIssues($capture));
            ['rows' => $rows, 'total' => $total] = $reader->captureRows($capture, $filters['record_type'], $page, self::PER_PAGE);
        } else {
            ['set' => $set, 'issues' => $issues] = $reader->live($filters['reference_date']);
            $counts = CicRecordsReader::countsByRecord($issues);
            $records = $filters['record_type'] === null ? $set->all() : $set->ofType($filters['record_type']);
            $total = count($records);
            $rows = array_map(fn (CicRecord $r) => [
                'record_type' => $r->type,
                'record_key' => $r->key,
                'borrower_id' => $r->borrowerId,
            ], array_slice($records, ($page - 1) * self::PER_PAGE, self::PER_PAGE));
        }

        $members = $this->seesPersonalData($request) ? CicRecordsReader::members(array_column($rows, 'borrower_id')) : null;

        $data = array_map(function (array $row) use ($counts, $members) {
            $count = $counts["{$row['record_type']}|{$row['record_key']}"] ?? ['errors' => 0, 'warnings' => 0];

            return [
                'record_type' => $row['record_type'],
                'record_key' => $row['record_key'],
                'errors' => $count['errors'],
                'warnings' => $count['warnings'],
                ...($members === null ? [] : ['member' => $members[$row['borrower_id']] ?? null]),
            ];
        }, $rows);

        return response()->json($this->page($data, $total, $page, [
            'source' => $capture === null ? 'live' : 'capture',
            'capture_id' => $capture?->id,
            'reference_date' => ($capture?->reference_date ?? $filters['reference_date'])->toDateString(),
        ]));
    }

    #[OA\Get(
        path: '/api/cic/records/{type}/{key}',
        summary: 'One CIC record',
        description: 'Needs `cic:view` and `branches:view_all`. `capture_id` (else live at `reference_date`). {record_type, record_key, errors, warnings, issues: [{record_type, record_key, field_id, field_name, classification, severity, code, message}], fields: [{field_id, name, type, classification, max_length, domain, value (only with borrowers:view)}], member: {name, code} (only with borrowers:view)}.',
        tags: ['CIC'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The record'), new OA\Response(response: 403, description: 'No access'), new OA\Response(response: 404, description: 'No such record')],
    )]
    public function show(Request $request, string $type, string $key, CicRecordsReader $reader): JsonResponse
    {
        $this->authorizeCic($request, CicAccess::VIEW);
        abort_unless(in_array($type, self::RECORD_TYPES, true), 404, 'Not found.');
        $filters = $this->recordFilters($request);
        $capture = $filters['capture'];

        if ($capture !== null) {
            $record = $reader->captureRecord($capture, $type, $key);
            abort_if($record === null, 404, 'Not found.');
            $issues = $reader->captureIssues($capture);
            [$values, $borrowerId] = [$record['values'], $record['borrower_id']];
        } else {
            $live = $reader->liveRecord($filters['reference_date'], $type, $key);
            abort_if($live === null, 404, 'Not found.');
            ['record' => $found, 'issues' => $issues] = $live;
            [$values, $borrowerId] = [$found->values, $found->borrowerId];
        }

        $own = array_values(array_filter($issues, fn (array $i) => $i['record_type'] === $type && $i['record_key'] === $key));
        $personal = $this->seesPersonalData($request);

        return response()->json(['success' => true, 'data' => [
            'record_type' => $type,
            'record_key' => $key,
            'source' => $capture === null ? 'live' : 'capture',
            'capture_id' => $capture?->id,
            'errors' => count(array_filter($own, fn (array $i) => $i['severity'] === 'error')),
            'warnings' => count(array_filter($own, fn (array $i) => $i['severity'] === 'warning')),
            'issues' => array_map([CicRecordsReader::class, 'present'], $own),
            'fields' => CicRecordsReader::fields($type, $values, $personal),
            ...($personal ? ['member' => CicRecordsReader::members([$borrowerId])[$borrowerId] ?? null] : []),
        ]]);
    }
}
