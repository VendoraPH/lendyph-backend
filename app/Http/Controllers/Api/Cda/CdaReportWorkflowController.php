<?php

namespace App\Http\Controllers\Api\Cda;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cda\Reports\CdaWorkflowRequest;
use App\Models\CdaReport;
use App\Services\Cda\Reports\ReportView;
use App\Services\Cda\Reports\ReportWorkflow;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * A step in a CDA report's workflow (design §5).
 */
class CdaReportWorkflowController extends Controller
{
    #[OA\Post(
        path: '/api/cda/reports/{report}/workflow',
        summary: 'Take a workflow step on a CDA report',
        description: 'Needs `branches:view_all`, the restricted view where the report has one, and the action\'s permission: refresh (alias validate), submit_for_review and set_stage need `cda:prepare`; return `cda:review`; approve `cda:approve` (never the person who submitted it: 403); finalize `cda:finalize`; mark_submitted, mark_accepted and mark_for_correction `cda:submit`; reopen `cda:reopen`. return, mark_for_correction and reopen need a `reason`. Critical issues block submit_for_review, approve and finalize (422, with `issues`); a document needed at filing blocks mark_submitted only. Warnings need `confirm_warnings: true` to finalize. Finalize answers 409 when a figure changed since approval, and writes the snapshot (version, SHA-256 checksum). mark_submitted {submission: {method, submitted_on, submitted_by_name?, cda_reference_no?, acknowledgment_no?, remarks?}} records the one filing. A step that doesn\'t fit the report\'s current status answers 409. Audited; approval needed, returned, finalized and for correction send in-app notices.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'report', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['action'], properties: [
            new OA\Property(property: 'action', type: 'string', enum: ['refresh', 'validate', 'submit_for_review', 'return', 'approve', 'finalize', 'mark_submitted', 'mark_accepted', 'mark_for_correction', 'reopen', 'set_stage']),
            new OA\Property(property: 'reason', type: 'string', nullable: true),
            new OA\Property(property: 'confirm_warnings', type: 'boolean'),
            new OA\Property(property: 'stage', type: 'string', nullable: true, enum: ['draft_fs', 'for_external_audit', 'audited', 'generated', 'for_signature', 'signed', 'uploaded']),
            new OA\Property(property: 'submission', type: 'object', nullable: true, properties: [
                new OA\Property(property: 'method', type: 'string', enum: ['cais', 'ecoophub', 'email', 'physical', 'other']),
                new OA\Property(property: 'submitted_on', type: 'string', format: 'date'),
                new OA\Property(property: 'submitted_by_name', type: 'string', nullable: true),
                new OA\Property(property: 'cda_reference_no', type: 'string', nullable: true),
                new OA\Property(property: 'acknowledgment_no', type: 'string', nullable: true),
                new OA\Property(property: 'remarks', type: 'string', nullable: true),
            ]),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The report after the step'),
            new OA\Response(response: 403, description: 'Missing the action\'s permission, branches:view_all or the restricted view; or approving your own submission'),
            new OA\Response(response: 409, description: 'The report\'s status changed, or a figure changed since approval'),
            new OA\Response(response: 422, description: 'Missing reason (or one with no letter or number), critical issues, unconfirmed warnings, or an invalid filing'),
        ],
    )]
    public function store(CdaWorkflowRequest $request, CdaReport $report, ReportWorkflow $workflow, ReportView $view): JsonResponse
    {
        $action = (string) $request->validated('action');
        $updated = $workflow->run($report, $action, $request->validated(), $request->user());

        return response()->json(['success' => true, 'message' => 'Done.', 'data' => $view->report($updated->refresh(), $request->user())]);
    }
}
