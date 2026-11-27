<?php

namespace App\Http\Controllers\Api\Cda;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cda\UpdateCdaReportDeadlineRequest;
use App\Http\Resources\Cda\CdaPresenter;
use App\Models\CdaReport;
use App\Services\AuditLogService;
use App\Services\Cda\CdaAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/**
 * A report's own deadline (CDA design §3, §10): it wins over the period's
 * extension and the default. A restricted report needs its view permission
 * as well.
 */
class CdaReportDeadlineController extends Controller
{
    #[OA\Patch(
        path: '/api/cda/reports/{id}/deadline',
        summary: 'Set or remove a report\'s own deadline',
        description: 'Needs `cda:settings` and `branches:view_all`, plus `cda:view_mediation`, `cda:view_audit` or `cda:view_tax` for a restricted report. `reference` is required with `override_on`; null removes the override. Audited on the report.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'override_on', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'reference', type: 'string', nullable: true),
        ])),
        responses: [new OA\Response(response: 200, description: 'The report row with its effective deadline'), new OA\Response(response: 403, description: 'Missing a permission'), new OA\Response(response: 404, description: 'No such report'), new OA\Response(response: 422, description: 'No reference')],
    )]
    public function update(UpdateCdaReportDeadlineRequest $request, int $report, CdaAccess $access): JsonResponse
    {
        // Looked up only after the form request checked the permission (C1
        // review, L4), so a caller without it can't probe which ids exist.
        $report = CdaReport::query()->find($report);
        abort_if($report === null, 404, 'There is no such CDA report.');
        $access->authorizeType($request->user(), $report->report_type);

        $report = DB::transaction(function () use ($request, $report): CdaReport {
            /** @var CdaReport $locked */
            $locked = CdaReport::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();
            $old = ['due_override_on' => $locked->due_override_on?->toDateString(), 'due_override_reference' => $locked->due_override_reference];
            $new = [
                'due_override_on' => $request->validated('override_on'),
                'due_override_reference' => $request->validated('override_on') === null ? null : $request->validated('reference'),
            ];

            if ($old !== $new) {
                $locked->forceFill($new)->save();
                AuditLogService::log('updated', $locked, $old, $new, $new['due_override_on'] === null
                    ? 'CDA report deadline override removed'
                    : "CDA report deadline set to {$new['due_override_on']} ({$new['due_override_reference']})");
            }

            return $locked;
        });

        return response()->json(['success' => true, 'message' => 'Deadline saved.', 'data' => CdaPresenter::reportRow($report->load('template'), $report->period, $request->user())]);
    }
}
