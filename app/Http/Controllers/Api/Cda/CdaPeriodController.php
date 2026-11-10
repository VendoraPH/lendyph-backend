<?php

namespace App\Http\Controllers\Api\Cda;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cda\StoreCdaPeriodRequest;
use App\Http\Requests\Cda\UpdateCdaPeriodRequest;
use App\Http\Resources\Cda\CdaPresenter;
use App\Models\CdaReport;
use App\Models\CdaReportingPeriod;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\PeriodOpener;
use App\Services\Cda\ReportTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/**
 * CDA reporting periods (CDA design §3): one per calendar year, opened with
 * a report instance per type and part. Every action needs
 * `branches:view_all` (whole-cooperative reports).
 */
class CdaPeriodController extends Controller
{
    public function __construct(private readonly CdaAccess $access) {}

    #[OA\Get(
        path: '/api/cda/periods',
        summary: 'CDA reporting periods, newest first',
        description: 'Needs `cda:view` and `branches:view_all`. Dates are YYYY-MM-DD; `effective_deadline` is the extension, else the default; `days_remaining` counts Manila days (negative once past).',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The periods, each with `report_count`'), new OA\Response(response: 403, description: 'Missing cda:view or branches:view_all')],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), CdaAccess::VIEW);

        $periods = CdaReportingPeriod::query()->with('creator')->withCount('reports')->orderByDesc('year')->get();

        return response()->json(['success' => true, 'data' => $periods->map(fn (CdaReportingPeriod $period) => [
            ...CdaPresenter::period($period),
            'report_count' => (int) $period->reports_count,
        ])->all()]);
    }

    #[OA\Get(
        path: '/api/cda/periods/{year}',
        summary: 'One CDA reporting period with its report instances',
        description: 'Needs `cda:view` and `branches:view_all`. Every instance is listed; one the caller may not open (mediation, audit or tax without its view permission) reads `restricted: true`.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The period and `reports`'), new OA\Response(response: 403, description: 'Missing cda:view or branches:view_all'), new OA\Response(response: 404, description: 'No period for that year')],
    )]
    public function show(Request $request, int $year): JsonResponse
    {
        $this->access->authorize($request->user(), CdaAccess::VIEW);
        $period = $this->find($year);

        return response()->json(['success' => true, 'data' => CdaPresenter::period($period, $request->user(), $this->reports($period))]);
    }

    #[OA\Post(
        path: '/api/cda/periods',
        summary: 'Open a CDA reporting period',
        description: 'Needs `cda:prepare` and `branches:view_all`. Creates the calendar year and one report per type and part (mediation H1 and H2), each with the active template selected for it. ATIR and ABR read `applicable: false` without a valid Certificate of Tax Exemption. A second period for the same year answers 409.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['year'], properties: [new OA\Property(property: 'year', type: 'integer', example: 2026)])),
        responses: [
            new OA\Response(response: 201, description: 'The period and its reports'),
            new OA\Response(response: 403, description: 'Missing cda:prepare or branches:view_all'),
            new OA\Response(response: 409, description: 'The year is already open'),
            new OA\Response(response: 422, description: 'A year before 2000 or after next year'),
        ],
    )]
    public function store(StoreCdaPeriodRequest $request, PeriodOpener $opener): JsonResponse
    {
        $period = $opener->open((int) $request->validated('year'), $request->user());

        return response()->json(['success' => true, 'data' => CdaPresenter::period($period->load('creator'), $request->user(), $this->reports($period))], 201);
    }

    #[OA\Patch(
        path: '/api/cda/periods/{year}',
        summary: 'Record or remove a deadline extension for the year\'s annual reports',
        description: 'Needs `cda:settings` and `branches:view_all`. `extended_deadline` must be after the default; `extension_reference` is required with it. Null removes the extension. Mediation reports keep their own deadlines. Audited.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'extended_deadline', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'extension_reference', type: 'string', nullable: true),
        ])),
        responses: [new OA\Response(response: 200, description: 'The period'), new OA\Response(response: 403, description: 'Missing cda:settings or branches:view_all'), new OA\Response(response: 404, description: 'No period for that year'), new OA\Response(response: 422, description: 'Not after the default, or no reference')],
    )]
    public function update(UpdateCdaPeriodRequest $request, int $year): JsonResponse
    {
        $period = DB::transaction(function () use ($request, $year): CdaReportingPeriod {
            $period = CdaReportingPeriod::query()->where('year', $year)->lockForUpdate()->first();
            abort_if($period === null, 404, "There is no CDA reporting period for {$year}.");

            $period->fill([
                'extended_deadline' => $request->validated('extended_deadline'),
                'extension_reference' => $request->validated('extended_deadline') === null ? null : $request->validated('extension_reference'),
            ]);

            if ($period->isDirty()) {
                $period->save();
            }

            return $period;
        });

        return response()->json(['success' => true, 'message' => 'Deadline saved.', 'data' => CdaPresenter::period($period->refresh()->load('creator'), $request->user(), $this->reports($period))]);
    }

    private function find(int $year): CdaReportingPeriod
    {
        $period = CdaReportingPeriod::query()->with('creator')->where('year', $year)->first();
        abort_if($period === null, 404, "There is no CDA reporting period for {$year}.");

        return $period;
    }

    /** @return Collection<int, CdaReport> */
    private function reports(CdaReportingPeriod $period)
    {
        $order = array_flip(ReportTypes::ALL);

        return $period->reports()->with('template')->get()
            ->sortBy(fn (CdaReport $report) => sprintf('%02d-%d', $order[$report->report_type] ?? 99, array_search($report->part, ReportTypes::PARTS, true)))
            ->values();
    }
}
