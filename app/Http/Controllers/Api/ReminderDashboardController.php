<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reminders\ReminderQueueFiltersRequest;
use App\Services\BranchAccess;
use App\Services\Reminders\Api\ReminderDashboard;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * The reminders dashboard: one Manila day's counts, the last 7 days, that
 * day's first 25 reminders and the global pause, over the caller's branches.
 */
class ReminderDashboardController extends Controller
{
    #[OA\Get(
        path: '/api/reminders/dashboard',
        summary: "One day's reminder counts, the last 7 days and that day's first 25 reminders",
        description: 'Sent counts leave out messages that were only logged (test mode); those are counted as logged. Also carries `test_mode` and `enabled`.',
        tags: ['Reminders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'date', in: 'query', required: false, description: 'Y-m-d, Manila; default today', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'channel', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['sms', 'email'])),
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Narrows `items` only', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'loan_product_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Member name or loan number', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'counts, sms, daily, items, global_pause, test_mode, enabled'),
            new OA\Response(response: 403, description: 'Missing reminders:view, or a branch_id outside the caller\'s branches'),
        ],
    )]
    public function show(ReminderQueueFiltersRequest $request, ReminderDashboard $dashboard, BranchAccess $branches): JsonResponse
    {
        $this->authorize('reminders:view');
        $branches->authorizeBranchFilter($request->user(), $request->validated('branch_id'));

        return response()->json(['data' => $dashboard->build($request->user(), $request->validated(), $request)]);
    }
}
