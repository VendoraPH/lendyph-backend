<?php

namespace App\Http\Controllers\Api\Cda;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cda\Reports\UpdateCdaSubmissionRequest;
use App\Http\Resources\Cda\CdaPresenter;
use App\Models\CdaReport;
use App\Models\CdaReportingPeriod;
use App\Models\CdaSubmission;
use App\Models\User;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Registers\SubmissionProofs;
use App\Services\Cda\ReportTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Filings with CDA (design §3): created only by the `mark_submitted`
 * workflow step, one per finalized version. Listed per year, without the
 * reports the caller can't open; their references and remarks can be
 * updated afterwards.
 */
class CdaSubmissionController extends Controller
{
    public function __construct(private readonly CdaAccess $access) {}

    #[OA\Get(
        path: '/api/cda/periods/{year}/submissions',
        summary: 'The year\'s filings with CDA',
        description: 'Needs `cda:view` and `branches:view_all`. Filings of reports the caller can\'t open (mediation, audit, tax without the view) are left out. Each: the report, the snapshot filed (version, checksum), method, submitted_on, submitted_by_name, recorded_by, cda_reference_no, acknowledgment_no, status, remarks and `proof` (the final proof of submission, its signed `url` null for a caller who may not open it; or null when none is uploaded).',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The filings, newest first'), new OA\Response(response: 403, description: 'Missing cda:view or branches:view_all'), new OA\Response(response: 404, description: 'No period for that year')],
    )]
    public function index(Request $request, int $year): JsonResponse
    {
        $this->access->authorize($request->user(), CdaAccess::VIEW);
        $period = CdaReportingPeriod::query()->where('year', $year)->first();
        abort_if($period === null, 404, "There is no CDA reporting period for {$year}.");

        $visible = $this->access->visibleTypes($request->user());
        $rows = CdaSubmission::query()->with(['report', 'snapshot'])
            ->whereHas('report', fn ($q) => $q->where('period_id', $period->id)->whereIn('report_type', $visible))
            ->orderByDesc('submitted_on')->orderByDesc('id')->get();

        return response()->json(['success' => true, 'data' => $rows->map(fn (CdaSubmission $row) => $this->present($row, $request->user()))->all()]);
    }

    #[OA\Put(
        path: '/api/cda/submissions/{submission}',
        summary: 'Update a filing\'s references and remarks',
        description: 'Needs `cda:submit`, `branches:view_all` and the report\'s restricted view. Takes cda_reference_no, acknowledgment_no, submitted_by_name and remarks only; the method, date, status and snapshot come from the workflow (422 if sent). Audited.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'submission', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'cda_reference_no', type: 'string', nullable: true),
            new OA\Property(property: 'acknowledgment_no', type: 'string', nullable: true),
            new OA\Property(property: 'submitted_by_name', type: 'string', nullable: true),
            new OA\Property(property: 'remarks', type: 'string', nullable: true),
        ])),
        responses: [new OA\Response(response: 200, description: 'The filing'), new OA\Response(response: 403, description: 'Missing a permission'), new OA\Response(response: 404, description: 'No such filing'), new OA\Response(response: 422, description: 'A field the workflow owns, or too long')],
    )]
    public function update(UpdateCdaSubmissionRequest $request, CdaSubmission $submission): JsonResponse
    {
        $submission->fill($request->validated());

        if ($submission->isDirty()) {
            $submission->save();
        }

        return response()->json(['success' => true, 'message' => 'Filing saved.', 'data' => $this->present($submission->refresh()->load(['report', 'snapshot']), $request->user())]);
    }

    /**
     * A filing as the API sends it. `proof` is the final proof of submission
     * (C3's SubmissionProofs), whose signed `url` is null for a viewer who
     * can't open it.
     *
     * @return array<string, mixed>
     */
    private function present(CdaSubmission $row, User $viewer): array
    {
        /** @var CdaReport $report */
        $report = $row->report;

        return [
            'id' => (int) $row->id,
            'report_id' => (int) $row->report_id,
            'report_type' => $report->report_type,
            'part' => $report->part,
            'title' => ReportTypes::title($report->report_type),
            'title_part' => ReportTypes::PART_TITLES[$report->part] ?? $report->part,
            'snapshot' => $row->snapshot === null ? null : ['id' => (int) $row->snapshot->id, 'version' => (int) $row->snapshot->version, 'checksum' => $row->snapshot->checksum],
            'method' => $row->method,
            'submitted_on' => $row->submitted_on?->toDateString(),
            'submitted_by_name' => $row->submitted_by_name,
            'recorded_by' => CdaPresenter::user($row->recorded_by === null ? null : User::query()->find($row->recorded_by)),
            'cda_reference_no' => $row->cda_reference_no,
            'acknowledgment_no' => $row->acknowledgment_no,
            'status' => $row->status,
            'remarks' => $row->remarks,
            'proof' => app(SubmissionProofs::class)->present($row, $viewer),
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }
}
