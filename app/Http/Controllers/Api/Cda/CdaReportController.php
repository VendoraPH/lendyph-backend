<?php

namespace App\Http\Controllers\Api\Cda;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cda\Reports\UpdateCdaReportRequest;
use App\Models\CdaReport;
use App\Models\CdaReportingPeriod;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Reports\ReportEditor;
use App\Services\Cda\Reports\ReportView;
use App\Services\Cda\ReportTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * One CDA report (design §3): read it, by id or by year and type, and save
 * what staff type on a draft. Every action needs `branches:view_all`, and a
 * restricted report (mediation, audit, tax) its view permission.
 */
class CdaReportController extends Controller
{
    public function __construct(
        private readonly CdaAccess $access,
        private readonly ReportView $view,
    ) {}

    #[OA\Get(
        path: '/api/cda/reports/{report}',
        summary: 'A CDA report: sections, fields with their sources, issues, readiness and history',
        description: 'Needs `cda:view`, `branches:view_all` and, for mediation, audit or tax reports, `cda:view_mediation`, `cda:view_audit` or `cda:view_tax`. Each field carries `value`, `source` (where it came from, in words), `source_key`, `source_value`, `provisional`, `overridden`, `editable` and `overridable`. Money and percents are 2-decimal strings. A finalized or later report is read from its snapshot. Reading never refreshes the figures.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'report', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The report'),
            new OA\Response(response: 403, description: 'Missing cda:view, branches:view_all or the restricted view'),
            new OA\Response(response: 404, description: 'No such report'),
        ],
    )]
    public function show(Request $request, CdaReport $report): JsonResponse
    {
        $this->access->authorize($request->user(), CdaAccess::VIEW);
        $this->access->authorizeType($request->user(), $report->report_type);

        return response()->json(['success' => true, 'data' => $this->view->report($report, $request->user())]);
    }

    #[OA\Get(
        path: '/api/cda/periods/{year}/reports/{type}',
        summary: 'A CDA report by year and type (alias of GET /cda/reports/{report})',
        description: 'Needs the same permissions as GET /cda/reports/{report}. `part` picks mediation\'s half (h1 or h2); annual reports ignore it.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'type', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ReportTypes::ALL)),
            new OA\Parameter(name: 'part', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['annual', 'h1', 'h2'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The report'),
            new OA\Response(response: 403, description: 'Missing cda:view, branches:view_all or the restricted view'),
            new OA\Response(response: 404, description: 'No period for that year, or no such report type'),
        ],
    )]
    public function showByType(Request $request, int $year, string $type): JsonResponse
    {
        $this->access->authorize($request->user(), CdaAccess::VIEW);
        abort_unless(ReportTypes::exists($type), 404, 'There is no CDA report of that type.');
        $this->access->authorizeType($request->user(), $type);

        $parts = ReportTypes::parts($type);
        $part = in_array($request->query('part'), $parts, true) ? (string) $request->query('part') : $parts[0];
        $period = CdaReportingPeriod::query()->where('year', $year)->first();
        abort_if($period === null, 404, "There is no CDA reporting period for {$year}.");

        $report = CdaReport::query()->where('period_id', $period->id)->where('report_type', $type)->where('part', $part)->first();
        abort_if($report === null, 404, 'This report hasn\'t been opened for that year.');

        return response()->json(['success' => true, 'data' => $this->view->report($report, $request->user())]);
    }

    #[OA\Put(
        path: '/api/cda/reports/{report}',
        summary: 'Save typed fields on a draft CDA report',
        description: 'Needs `cda:prepare`, `branches:view_all` and the restricted view where the report has one. Body: `fields: [{field_key, value?, remarks?, override_reason?, evidence?, revert?}]`, where `field_key` is "section.field" (a table section by its section key). Manual fields take a value of their type; a sourced field only when the template allows an override, with `override_reason` (never a loans, accounting, financial statement, share capital or savings figure); `revert: true` drops an override; calculated fields never. Remarks and evidence go on any field. The calculations, issues and readiness are recomputed at once. Audited.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'report', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['fields'], properties: [
            new OA\Property(property: 'fields', type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'field_key', type: 'string', example: 'employment.employees_regular'),
                new OA\Property(property: 'value', example: 12),
                new OA\Property(property: 'remarks', type: 'string', nullable: true),
                new OA\Property(property: 'override_reason', type: 'string', nullable: true),
                new OA\Property(property: 'evidence', type: 'array', nullable: true, items: new OA\Items(type: 'object')),
                new OA\Property(property: 'revert', type: 'boolean'),
            ])),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The report, recomputed'),
            new OA\Response(response: 403, description: 'Missing cda:prepare, branches:view_all or the restricted view'),
            new OA\Response(response: 409, description: 'The report isn\'t a draft'),
            new OA\Response(response: 422, description: 'A field that can\'t be typed, a value of the wrong type, or an override without a reason'),
        ],
    )]
    public function update(UpdateCdaReportRequest $request, CdaReport $report, ReportEditor $editor): JsonResponse
    {
        $saved = $editor->save($report, $request->validated('fields'), $request->user());

        return response()->json(['success' => true, 'message' => 'Report saved.', 'data' => $this->view->report($saved, $request->user())]);
    }
}
