<?php

namespace App\Http\Controllers\Api\Cda;

use App\Http\Controllers\Controller;
use App\Models\CdaReport;
use App\Models\CdaReportSnapshot;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Reports\ReportView;
use App\Services\Cda\Reports\SnapshotWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * A CDA report's finalized snapshots (design §5): every version, kept when
 * a reopen supersedes it, with its SHA-256 checksum, and a check that the
 * stored payload still matches it.
 */
class CdaSnapshotController extends Controller
{
    public function __construct(private readonly CdaAccess $access) {}

    #[OA\Get(
        path: '/api/cda/reports/{report}/snapshots',
        summary: 'A CDA report\'s snapshots, newest first',
        description: 'Needs `cda:view`, `branches:view_all` and the restricted view where the report has one. Each: id, version, checksum, finalized_at and by, superseded_at, reopened_by and reopen_reason.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'report', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The snapshots'), new OA\Response(response: 403, description: 'Missing a permission'), new OA\Response(response: 404, description: 'No such report')],
    )]
    public function index(Request $request, CdaReport $report): JsonResponse
    {
        $this->authorizeReport($request, $report);

        return response()->json(['success' => true, 'data' => $report->snapshots()->orderByDesc('version')->get()
            ->map(fn (CdaReportSnapshot $snapshot) => ReportView::snapshotSummary($snapshot))->all()]);
    }

    #[OA\Get(
        path: '/api/cda/reports/{report}/snapshots/{version}',
        summary: 'One snapshot with its payload',
        description: 'Needs the same permissions as the list. `payload` is the frozen report: profile, template and definition hash, values with sources and overrides, issues, readiness, who finalized it and when.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'report', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'version', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'The snapshot'), new OA\Response(response: 403, description: 'Missing a permission'), new OA\Response(response: 404, description: 'No such version')],
    )]
    public function show(Request $request, CdaReport $report, int $version): JsonResponse
    {
        $this->authorizeReport($request, $report);
        $snapshot = $this->find($report, $version);

        return response()->json(['success' => true, 'data' => [
            ...ReportView::snapshotSummary($snapshot),
            'payload' => json_decode($snapshot->payload, true),
        ]]);
    }

    #[OA\Post(
        path: '/api/cda/reports/{report}/snapshots/{version}/verify',
        summary: 'Check a snapshot against its checksum',
        description: 'Needs the same permissions as the list. Recomputes the SHA-256 of the stored payload and checks it is still canonical JSON. Writes nothing.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'report', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'version', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: '{version, checksum, checksum_matches}'), new OA\Response(response: 403, description: 'Missing a permission'), new OA\Response(response: 404, description: 'No such version')],
    )]
    public function verify(Request $request, CdaReport $report, int $version): JsonResponse
    {
        $this->authorizeReport($request, $report);
        $snapshot = $this->find($report, $version);

        return response()->json(['success' => true, 'data' => [
            'version' => (int) $snapshot->version,
            'checksum' => $snapshot->checksum,
            'checksum_matches' => SnapshotWriter::verify($snapshot),
        ]]);
    }

    private function authorizeReport(Request $request, CdaReport $report): void
    {
        $this->access->authorize($request->user(), CdaAccess::VIEW);
        $this->access->authorizeType($request->user(), $report->report_type);
    }

    private function find(CdaReport $report, int $version): CdaReportSnapshot
    {
        $snapshot = $report->snapshots()->where('version', $version)->first();
        abort_if($snapshot === null, 404, "This report has no version {$version}.");

        return $snapshot;
    }
}
