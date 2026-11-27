<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reminders\ReminderHistoryFiltersRequest;
use App\Http\Resources\Reminders\ReminderMessageResource;
use App\Models\ReminderMessage;
use App\Services\BranchAccess;
use App\Services\Reminders\Api\ReminderLists;
use App\Services\Reminders\Api\ReminderScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * Messages handed to a driver. Recipients are masked; the full number or
 * address never leaves the table. A message on a loan the caller can't see
 * answers 404.
 */
class ReminderHistoryController extends Controller
{
    #[OA\Get(
        path: '/api/reminders/history',
        summary: 'Messages handed to a driver, paged, with per-status counts in meta.stats',
        description: '`date`, `date_from` and `date_to` are the send date; `status` is a delivery status. `per_page` defaults to 25 and is held between 1 and 100.',
        tags: ['Reminders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ReminderLists::MESSAGE_STATUSES)),
            new OA\Parameter(name: 'channel', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['sms', 'email'])),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Laravel paginator {data, links, meta} with meta.stats'),
            new OA\Response(response: 403, description: 'Missing reminders:view, or a branch_id outside the caller\'s branches'),
        ],
    )]
    public function index(ReminderHistoryFiltersRequest $request, ReminderLists $lists, BranchAccess $branches): AnonymousResourceCollection
    {
        $this->authorize('reminders:view');
        $branches->authorizeBranchFilter($request->user(), $request->validated('branch_id'));
        [$page, $stats] = $lists->history($request->user(), $request->validated());

        return ReminderMessageResource::collection($page)->additional(['meta' => ['stats' => $stats]]);
    }

    #[OA\Get(
        path: '/api/reminders/history/{id}',
        summary: 'One message, exactly as it was handed over (null text once redacted)',
        tags: ['Reminders'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The message'),
            new OA\Response(response: 403, description: 'Missing reminders:view'),
            new OA\Response(response: 404, description: 'No such message on a loan the caller can see'),
        ],
    )]
    public function show(Request $request, int $id, ReminderScope $scope): JsonResponse
    {
        $this->authorize('reminders:view');
        $message = $scope->visible(ReminderMessage::query(), $request->user())
            ->with(ReminderLists::MESSAGE_RELATIONS)
            ->whereKey($id)
            ->firstOrFail();

        return response()->json(['data' => (new ReminderMessageResource($message))->resolve($request)]);
    }
}
