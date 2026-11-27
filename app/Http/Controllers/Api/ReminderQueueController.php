<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reminders\ReminderQueueFiltersRequest;
use App\Http\Resources\Reminders\ReminderQueueItemResource;
use App\Services\BranchAccess;
use App\Services\Reminders\Api\ReminderLists;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class ReminderQueueController extends Controller
{
    #[OA\Get(
        path: '/api/reminders/queue',
        summary: 'Reminders in the queue, paged, with per-status counts in meta.stats',
        description: 'Only reminders on loans the caller can see. `date`, `date_from` and `date_to` are the fire date. `per_page` defaults to 25 and is held between 1 and 100. `meta.stats` counts each status for the same filters without `status`.',
        tags: ['Reminders'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ReminderLists::QUEUE_STATUSES)),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Laravel paginator {data, links, meta} with meta.stats'),
            new OA\Response(response: 403, description: 'Missing reminders:view, or a branch_id outside the caller\'s branches'),
        ],
    )]
    public function index(ReminderQueueFiltersRequest $request, ReminderLists $lists, BranchAccess $branches): AnonymousResourceCollection
    {
        $this->authorize('reminders:view');
        $branches->authorizeBranchFilter($request->user(), $request->validated('branch_id'));
        [$page, $stats] = $lists->queue($request->user(), $request->validated());

        return ReminderQueueItemResource::collection($page)->additional(['meta' => ['stats' => $stats]]);
    }
}
