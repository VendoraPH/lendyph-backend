<?php

namespace App\Http\Controllers\Api\Cda;

use App\Http\Controllers\Controller;
use App\Models\CdaReportingPeriod;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Reports\ReportDashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * A reporting year at a glance (design §3): the dashboard and the
 * compliance calendar, from stored readiness and issues.
 */
class CdaDashboardController extends Controller
{
    public function __construct(
        private readonly CdaAccess $access,
        private readonly ReportDashboard $dashboard,
    ) {}

    #[OA\Get(
        path: '/api/cda/periods/{year}/dashboard',
        summary: 'The CDA dashboard for a year',
        description: 'Needs `cda:view` and `branches:view_all`. {period, readiness ("Lendy CDA Readiness", the mean of the applicable reports), readiness_label, counts, next_deadline, reports[{id, type, report_type, part, title, title_part, status, display_status, sub_status, readiness, critical, warnings, applicable, restricted, effective_deadline, days_remaining, updated_at}], issues}. Reports the caller can\'t open (mediation, audit, tax without their view) are left out entirely: no row, no counts, no readiness, no next deadline and no issues. A locked report\'s documents needed at filing are checked live.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The dashboard'), new OA\Response(response: 403, description: 'Missing cda:view or branches:view_all'), new OA\Response(response: 404, description: 'No period for that year')],
    )]
    public function dashboard(Request $request, int $year): JsonResponse
    {
        $this->access->authorize($request->user(), CdaAccess::VIEW);

        return response()->json(['success' => true, 'data' => $this->dashboard->dashboard($this->period($year), $request->user())]);
    }

    #[OA\Get(
        path: '/api/cda/periods/{year}/calendar',
        summary: 'The CDA compliance calendar for a year',
        description: 'Needs `cda:view` and `branches:view_all`. One row per report instance the caller can open (mediation H1 and H2 separately; restricted reports without their view are left out), by effective deadline: the report row (`kind: report`), `display_status`, `readiness` and `filed`; the year\'s custom compliance requirements are listed among them (`kind: requirement`, effective deadline = the extension, else the default). Deadlines are YYYY-MM-DD; `days_remaining` counts Manila days, negative once past.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The calendar rows'), new OA\Response(response: 403, description: 'Missing cda:view or branches:view_all'), new OA\Response(response: 404, description: 'No period for that year')],
    )]
    public function calendar(Request $request, int $year): JsonResponse
    {
        $this->access->authorize($request->user(), CdaAccess::VIEW);

        return response()->json(['success' => true, 'data' => $this->dashboard->calendar($this->period($year), $request->user())]);
    }

    private function period(int $year): CdaReportingPeriod
    {
        $period = CdaReportingPeriod::query()->where('year', $year)->first();
        abort_if($period === null, 404, "There is no CDA reporting period for {$year}.");

        return $period;
    }
}
