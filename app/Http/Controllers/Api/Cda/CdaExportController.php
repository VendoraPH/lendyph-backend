<?php

namespace App\Http\Controllers\Api\Cda;

use App\Http\Controllers\Controller;
use App\Http\Resources\Cda\CdaPresenter;
use App\Models\CdaDocumentVersion;
use App\Models\CdaReport;
use App\Models\CdaReportSnapshot;
use App\Models\User;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Exports\ReportExporter;
use App\Services\SignedFileLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * Server exports of a CDA report (CDA design §9): generated on request,
 * kept as document versions, downloaded through a signed link bound to the
 * user (30 minutes). Restricted reports also need their view permission.
 */
#[OA\Schema(schema: 'CdaExport', properties: [
    new OA\Property(property: 'id', type: 'integer', description: 'The document version'),
    new OA\Property(property: 'document_id', type: 'integer', description: 'The CDA repository document'),
    new OA\Property(property: 'version', type: 'integer'),
    new OA\Property(property: 'format', type: 'string', enum: ['pdf', 'xlsx']),
    new OA\Property(property: 'is_draft', type: 'boolean'),
    new OA\Property(property: 'file_name', type: 'string', example: 'CAPR-CY2026-V1-DRAFT.pdf'),
    new OA\Property(property: 'sha256', type: 'string'),
    new OA\Property(property: 'snapshot_version', type: 'integer', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'url', type: 'string', description: 'Signed, 30 minutes, bound to the caller'),
    new OA\Property(property: 'generated_by', type: 'object', nullable: true),
    new OA\Property(property: 'generated_at', type: 'string', format: 'date-time'),
])]
class CdaExportController extends Controller
{
    public function __construct(private readonly CdaAccess $access) {}

    #[OA\Get(
        path: '/api/cda/reports/{id}/exports',
        summary: 'The exports generated for a CDA report, newest first',
        description: 'Needs `cda:view` and `branches:view_all`, and the report\'s view permission when it is restricted (mediation, audit or tax). Each row has a fresh signed `url` (30 minutes, bound to the caller).',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The exports', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CdaExport')),
            ])),
            new OA\Response(response: 403, description: 'Missing cda:view, branches:view_all or the report\'s view permission'),
            new OA\Response(response: 404, description: 'No such report'),
        ],
    )]
    public function index(Request $request, CdaReport $report): JsonResponse
    {
        $this->access->authorize($request->user(), CdaAccess::VIEW);
        $this->access->authorizeType($request->user(), $report->report_type);

        $versions = CdaDocumentVersion::query()
            ->with(['file', 'cdaDocument'])
            ->whereHas('cdaDocument', fn ($documents) => $documents->where('report_id', $report->id)->where('category', ReportExporter::CATEGORY))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get();

        return response()->json(['success' => true, 'data' => $this->presentAll($versions)]);
    }

    #[OA\Post(
        path: '/api/cda/reports/{id}/exports',
        summary: 'Export a CDA report as PDF or Excel',
        description: 'Needs `cda:export` and `branches:view_all`, and the report\'s view permission when it is restricted. Until the report is finalized the export renders the live values with a "DRAFT — not for submission" mark and a file name ending in -DRAFT; from finalized on it renders the snapshot. Every page carries a footer (page, period, generation time in Manila, who generated it, template version, snapshot version and checksum). Excel fills the template\'s official workbook when one is attached (`export.excel.mode: template`), else a generated layout. Audited. A server without the PHP zip extension answers 503 for Excel.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['format'], properties: [
            new OA\Property(property: 'format', type: 'string', enum: ReportExporter::FORMATS),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The export', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Export ready.'),
                new OA\Property(property: 'data', ref: '#/components/schemas/CdaExport'),
            ])),
            new OA\Response(response: 403, description: 'Missing cda:export, branches:view_all or the report\'s view permission'),
            new OA\Response(response: 404, description: 'No such report'),
            new OA\Response(response: 409, description: 'The report changed while it was being exported'),
            new OA\Response(response: 422, description: 'A format other than pdf or xlsx'),
            new OA\Response(response: 503, description: 'Excel without the PHP zip extension'),
        ],
    )]
    public function store(Request $request, CdaReport $report, ReportExporter $exporter): JsonResponse
    {
        $user = $request->user();
        $this->access->authorize($user, CdaAccess::EXPORT);
        $this->access->authorizeType($user, $report->report_type);
        $input = $request->validate(
            ['format' => ['required', 'string', Rule::in(ReportExporter::FORMATS)]],
            ['format.required' => 'Pick PDF (pdf) or Excel (xlsx).', 'format.string' => 'Pick PDF (pdf) or Excel (xlsx).', 'format.in' => 'Pick PDF (pdf) or Excel (xlsx).'],
        );

        $version = $exporter->export($report, $input['format'], $user);

        return response()->json(['success' => true, 'message' => 'Export ready.', 'data' => $this->presentAll(collect([$version]))[0]], 201);
    }

    /**
     * @param  Collection<int, CdaDocumentVersion>  $versions
     * @return list<array<string, mixed>>
     */
    private function presentAll(Collection $versions): array
    {
        $snapshots = CdaReportSnapshot::query()->whereIn('id', $versions->pluck('snapshot_id')->filter()->unique())->pluck('version', 'id');
        $users = User::query()->whereIn('id', $versions->pluck('uploaded_by')->filter()->unique())->get()->keyBy('id');

        return $versions->map(fn (CdaDocumentVersion $version): array => [
            'id' => (int) $version->id,
            'document_id' => (int) $version->cda_document_id,
            'version' => (int) $version->version,
            'format' => $version->format,
            'is_draft' => (bool) $version->is_draft,
            'file_name' => $version->file?->original_filename,
            'sha256' => $version->sha256,
            'snapshot_version' => $version->snapshot_id === null ? null : (int) $snapshots->get($version->snapshot_id),
            'notes' => $version->notes,
            'url' => SignedFileLink::to('files.document', ['document' => $version->document_id]),
            'generated_by' => CdaPresenter::user($users->get($version->uploaded_by)),
            'generated_at' => $version->created_at,
        ])->values()->all();
    }
}
