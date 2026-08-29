<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Traits\CsvExportTrait;
use App\Http\Controllers\Api\Traits\ResolvesImportRuns;
use App\Http\Controllers\Controller;
use App\Services\CsvImport\ErrorReportBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What went wrong with a run, and what quietly went differently.
 *
 * Two views of one dataset: a paginated list for the screen, and a streamed CSV
 * for the admin who is going to fix the source file in Excel. Both carry ERRORS
 * AND WARNINGS, because the rows that succeeded with their data changed are the
 * ones nobody goes back to check.
 */
class CsvImportErrorReportController extends Controller
{
    use CsvExportTrait;

    /** The run id is resolved AFTER the permission check — see the trait. */
    use ResolvesImportRuns;

    public function __construct(private ErrorReportBuilder $report) {}

    #[OA\Get(
        path: '/api/imports/{run}/errors',
        summary: 'Paginated import issues, plus a summary grouped by reason',
        description: 'Errors and warnings for every reported row. `meta.total` counts REPORTED ROWS — the pagination '
            .'unit — while `meta.stats.total_issues` counts the issue lines in `data`, since one row can carry '
            .'several. `meta.stats.by_category` is the grouped view an admin acts on.',
        tags: ['CSV Import'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'run', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'severity', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['error', 'warning'])),
            new OA\Parameter(name: 'kind', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['customers', 'loans'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated issues'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function index(Request $request, int $run): JsonResponse
    {
        $this->authorize('imports:process');

        $run = $this->importRun($run);

        $filters = $this->validatedFilters($request);
        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);

        $run->loadMissing('files');

        ['paginator' => $paginator, 'issues' => $issues] = $this->report->paginate($run, $filters, $perPage);

        /**
         * The summary is computed on the first page only.
         *
         * It is a walk of every reported row in the run — warnings live inside
         * `normalized`, so that leg cannot use the
         * `(csv_import_file_id, status, result)` index and scans — while the
         * page itself is index-served and cheap. Recomputing it for page 2, 3
         * and 47 re-scans the table for an answer that has not changed and that
         * the client already has: the grouped view is a header block, fetched
         * once with page one. `stats_omitted` says so explicitly rather than
         * letting a null read as "no issues".
         */
        $firstPage = $paginator->currentPage() === 1;

        return response()->json([
            'data' => $issues,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                // Reported ROWS. See `stats.total_issues` for the line count.
                'total' => $paginator->total(),
                'unit' => 'row',
                'rows_on_page' => count($paginator->items()),
                'issues_on_page' => count($issues),
                'stats' => $firstPage ? $this->report->stats($run, $filters) : null,
                'stats_omitted' => ! $firstPage,
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/imports/{run}/errors.csv',
        summary: 'Stream the full error and warning report as CSV',
        description: 'Never written to disk and never cached — generated per request, so it is always current and no '
            .'scheduled process ever leaves a file behind for php-fpm to read back. One line per issue.',
        tags: ['CSV Import'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'run', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'severity', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['error', 'warning'])),
            new OA\Parameter(name: 'kind', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['customers', 'loans'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'CSV file stream', content: new OA\MediaType(mediaType: 'text/csv')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function export(Request $request, int $run): StreamedResponse
    {
        $this->authorize('imports:process');

        $run = $this->importRun($run);

        /**
         * Validated before a byte is streamed. The generator below runs after
         * the response has begun, so a ValidationException thrown from inside it
         * would be appended to a half-written CSV instead of becoming a 422 —
         * the trap AuditLogController::export() documents.
         */
        $filters = $this->validatedFilters($request);

        $run->loadMissing('files');

        $response = $this->streamCsv(
            'import-'.$run->id.'-errors-'.now()->format('Ymd-His').'.csv',
            ErrorReportBuilder::CSV_HEADERS,
            /**
             * A generator, so rows reach the socket as they are read. The
             * report is never materialised in memory and never written to disk:
             * a file would have to be produced by something, read back by
             * php-fpm, and would be stale the moment the run advanced.
             *
             * CsvExportTrait::streamCsv() neutralises a leading `=`, `+`, `-`,
             * `@`, tab or CR on EVERY cell it writes, which is what keeps both
             * Original Value and Message safe — Message interpolates the same
             * cell Original Value echoes, so sanitising one and not the other
             * reopens the hole through the second column.
             */
            $this->report->csvRows($run, $filters),
        );

        /**
         * Said explicitly rather than left to Symfony's default. This file
         * holds members' names, birthdates, phone numbers and addresses, and
         * the default `no-cache, private` stops being enough the moment anyone
         * puts a cache in front of this API — which the fleet has done before.
         */
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');

        return $response;
    }

    /**
     * @return array{severity: string|null, kind: string|null, per_page: int|null}
     */
    private function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'severity' => ['nullable', 'string', 'in:error,warning'],
            'kind' => ['nullable', 'string', 'in:customers,loans'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        return [
            'severity' => $validated['severity'] ?? null,
            'kind' => $validated['kind'] ?? null,
            'per_page' => isset($validated['per_page']) ? (int) $validated['per_page'] : null,
        ];
    }
}
