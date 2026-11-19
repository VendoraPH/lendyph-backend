<?php

namespace App\Http\Controllers\Api\Branches;

use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\ChangeBranchStatusRequest;
use App\Http\Requests\Branch\MoveMainOfficeRequest;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use App\Services\Branches\BranchStatus;
use OpenApi\Attributes as OA;

/**
 * A branch's status and the main-office flag (Phase 6, multi-branch
 * decisions 2 and 4). Both need `branches:manage` plus `branches:view_all`
 * (BranchStatusRequest); a hidden branch answers 404.
 */
class BranchStatusController extends Controller
{
    public function __construct(private BranchStatus $status) {}

    #[OA\Patch(
        path: '/api/branches/{branch}/status',
        summary: 'Suspend or reactivate a branch',
        description: 'Active or suspended, with a reason. A closed branch stays closed, and the main office stays active. Closing goes through the closure request.',
        tags: ['Branches'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'branch', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['status', 'reason'],
            properties: [
                new OA\Property(property: 'status', type: 'string', enum: ['active', 'suspended']),
                new OA\Property(property: 'reason', type: 'string', maxLength: 500),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'The branch'),
            new OA\Response(response: 403, description: 'Needs branches:manage and branches:view_all'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Refused, in words'),
        ],
    )]
    public function update(ChangeBranchStatusRequest $request, Branch $branch): BranchResource
    {
        $changed = $this->status->change($branch, $request->validated('status'), $request->validated('reason'));

        return new BranchResource($changed->load('manager:id,first_name,last_name'));
    }

    #[OA\Put(
        path: '/api/branches/{branch}/main-office',
        summary: 'Make a branch the main office',
        description: 'Moves the main-office flag to this active branch; the old main office is unflagged. Each branch gets an audit row with the reason.',
        tags: ['Branches'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'branch', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['reason'],
            properties: [new OA\Property(property: 'reason', type: 'string', maxLength: 500)],
        )),
        responses: [
            new OA\Response(response: 200, description: 'The branch'),
            new OA\Response(response: 403, description: 'Needs branches:manage and branches:view_all'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Only an active branch can be the main office'),
        ],
    )]
    public function mainOffice(MoveMainOfficeRequest $request, Branch $branch): BranchResource
    {
        return new BranchResource($this->status->makeMainOffice($branch, $request->validated('reason'))->load('manager:id,first_name,last_name'));
    }
}
