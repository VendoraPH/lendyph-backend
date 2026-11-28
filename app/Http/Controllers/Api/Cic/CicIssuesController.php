<?php

namespace App\Http\Controllers\Api\Cic;

use App\Http\Controllers\Api\Cic\Concerns\ReadsCicRecords;
use App\Http\Controllers\Api\Traits\CsvExportTrait;
use App\Models\CicCapture;
use App\Models\CicCaptureRecord;
use App\Services\AuditLogService;
use App\Services\Cic\CicAccess;
use App\Services\Cic\CicIssueWords;
use App\Services\Cic\Records\CicRecord;
use App\Services\Cic\Records\CicRecordSet;
use App\Services\Cic\Records\CicRecordsReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every CIC issue, errors first, from a capture or a live build, filtered by
 * severity and record type. An issue names its member only for a caller
 * holding `borrowers:view`.
 */
class CicIssuesController extends CicController
{
    use CsvExportTrait;
    use ReadsCicRecords;

    #[OA\Get(
        path: '/api/cic/issues',
        summary: 'CIC issues',
        description: 'Needs `cic:view` and `branches:view_all`. `severity` (error, warning), `record_type` (ID, CI, CN, NE), `capture_id` (else live at `reference_date`), `page` (25 a page). {data: [{record_type, record_key, field_id, field_name, classification, severity, code, code_label (the code in words), message, member: {name, code} (only with borrowers:view)}], meta: {current_page, last_page, per_page, total, errors, warnings, source, capture_id, reference_date}}. File-level issues have record_type HD or FT and an empty record_key.',
        tags: ['CIC'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The issues'), new OA\Response(response: 403, description: 'No access'), new OA\Response(response: 404, description: 'No such capture'), new OA\Response(response: 422, description: 'Bad filter')],
    )]
    public function index(Request $request, CicRecordsReader $reader): JsonResponse
    {
        $this->authorizeCic($request, CicAccess::VIEW);
        $filters = $this->recordFilters($request);
        ['capture' => $capture, 'set' => $set, 'matching' => $matching] = $this->matching($filters, $reader);
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

    #[OA\Get(
        path: '/api/cic/issues/export',
        summary: 'Export CIC issues as CSV',
        description: 'Needs `cic:view` and `branches:view_all`; throttled like other exports (5 a minute). The filters of /cic/issues (`severity`, `record_type`, `capture_id`, `reference_date`; no paging). A CSV built on the server, every matching issue, errors first: Record type, Record key, Field ID, Field name, Classification, Severity, Issue, Message, and Member (name and code) only with borrowers:view. Codes read in words. One audit row per export: the filters and the row count, no values.',
        tags: ['CIC'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'text/csv'), new OA\Response(response: 403, description: 'No access'), new OA\Response(response: 422, description: 'Bad filter, in words')],
    )]
    public function export(Request $request, CicRecordsReader $reader): StreamedResponse
    {
        $user = $this->authorizeCic($request, CicAccess::VIEW);
        $filters = $this->recordFilters($request);
        ['capture' => $capture, 'set' => $set, 'matching' => $matching] = $this->matching($filters, $reader);
        $withMembers = $this->seesPersonalData($request);
        $members = $withMembers ? $this->membersOf($matching, $capture?->id, $set?->all()) : [];
        $referenceDate = ($capture?->reference_date ?? $filters['reference_date'])->toDateString();

        $headers = ['Record type', 'Record key', 'Field ID', 'Field name', 'Classification', 'Severity', 'Issue', 'Message'];

        if ($withMembers) {
            $headers[] = 'Member';
        }

        $rows = array_map(function (array $issue) use ($withMembers, $members) {
            $issue = CicRecordsReader::present($issue);
            $row = [
                CicIssueWords::recordType($issue['record_type']),
                $issue['record_key'],
                (string) ($issue['field_id'] ?? ''),
                (string) ($issue['field_name'] ?? ''),
                CicIssueWords::classification($issue['classification']),
                CicIssueWords::severity($issue['severity']),
                CicIssueWords::code($issue['code']),
                $issue['message'],
            ];

            if ($withMembers) {
                $member = $members["{$issue['record_type']}|{$issue['record_key']}"] ?? null;
                $row[] = $member === null ? '' : "{$member['name']} ({$member['code']})";
            }

            return $row;
        }, $matching);

        $source = $capture === null ? "live build as of {$referenceDate}" : "capture #{$capture->id} ({$referenceDate})";
        AuditLogService::log('cic_issues_exported', $capture, null, [
            'source' => $capture === null ? 'live' : 'capture',
            'capture_id' => $capture?->id,
            'reference_date' => $referenceDate,
            'severity' => $filters['severity'],
            'record_type' => $filters['record_type'],
            'rows' => count($rows),
            'with_member_names' => $withMembers,
        ], sprintf('CIC issues exported from the %s: %d %s%s.', $source, count($rows), count($rows) === 1 ? 'row' : 'rows', self::filterWords($filters)), $user->id);

        $name = $capture === null ? "cic-issues-{$referenceDate}.csv" : "cic-issues-capture-{$capture->id}-{$referenceDate}.csv";

        return $this->streamCsv($name, $headers, $rows, responseHeaders: ['Cache-Control' => 'no-store, private']);
    }

    /**
     * The issues the filters select, errors first, from the capture or a live build.
     *
     * @param  array{reference_date: Carbon, capture: CicCapture|null, record_type: string|null, severity: string|null, page: int}  $filters
     * @return array{capture: CicCapture|null, set: CicRecordSet|null, matching: list<array<string, mixed>>}
     */
    private function matching(array $filters, CicRecordsReader $reader): array
    {
        $capture = $filters['capture'];
        $set = null;

        if ($capture !== null) {
            $issues = $reader->captureIssues($capture);
        } else {
            ['set' => $set, 'issues' => $issues] = $reader->live($filters['reference_date']);
        }

        $matching = array_values(array_filter($issues, fn (array $i) => ($filters['severity'] === null || $i['severity'] === $filters['severity'])
            && ($filters['record_type'] === null || $i['record_type'] === $filters['record_type'])));

        return ['capture' => $capture, 'set' => $set, 'matching' => $matching];
    }

    /** @param  array{record_type: string|null, severity: string|null}  $filters */
    private static function filterWords(array $filters): string
    {
        $parts = array_filter([
            $filters['severity'] === null ? null : ($filters['severity'] === 'error' ? 'errors only' : 'warnings only'),
            $filters['record_type'] === null ? null : CicIssueWords::recordType($filters['record_type']).' records only',
        ]);

        return $parts === [] ? '' : ' ('.implode(', ', $parts).')';
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
            // An export asks for every issue's record, so look them up in chunks.
            foreach (array_chunk($wanted, 500) as $chunk) {
                CicCaptureRecord::query()->where('capture_id', $captureId)
                    ->where(function ($query) use ($chunk) {
                        foreach ($chunk as [$type, $key]) {
                            $query->orWhere(fn ($q) => $q->where('record_type', $type)->where('record_key', $key));
                        }
                    })
                    ->with(['subject:id,borrower_id', 'loan:id,borrower_id'])
                    ->get(['id', 'record_type', 'record_key', 'cic_subject_id', 'loan_id'])
                    ->each(function (CicCaptureRecord $r) use (&$borrowers) {
                        $borrowers["{$r->record_type}|{$r->record_key}"] = $r->subject?->borrower_id ?? $r->loan?->borrower_id;
                    });
            }
        }

        $members = CicRecordsReader::members(array_values($borrowers));

        return array_filter(array_map(fn (?int $id) => $id === null ? null : ($members[$id] ?? null), $borrowers));
    }
}
