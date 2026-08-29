<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Traits\ResolvesImportRuns;
use App\Http\Controllers\Controller;
use App\Services\CsvImport\RunStatusReader;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * The one endpoint a browser polls for the whole length of an import.
 *
 * Follows AutoCreditController's status/action pairing: a cheap, repeatable
 * read that reports what is true, next to a separate action that changes it.
 * Everything expensive — the distinct product strings, the grouped error
 * summary — lives on the endpoints an operator opens deliberately, never here.
 */
class CsvImportStatusController extends Controller
{
    /** The run id is resolved AFTER the permission check — see the trait. */
    use ResolvesImportRuns;

    public function __construct(private RunStatusReader $reader) {}

    #[OA\Get(
        path: '/api/imports/{run}',
        summary: 'Import run status, per-file progress and staleness',
        description: 'Phase, whether the product mapping still blocks the run, whether an error report can be '
            .'downloaded, a server-clock `last_advanced_at` with the elapsed seconds already computed, the missing '
            .'chunk indexes per file, and per-file row outcome counts — including `matched_existing` as its own '
            .'number, separate from `skipped`.',
        tags: ['CSV Import'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'run', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Run status'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(int $run): JsonResponse
    {
        $this->authorize('imports:process');

        return response()->json(['data' => $this->reader->payload($this->importRun($run))]);
    }
}
