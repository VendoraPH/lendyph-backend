<?php

namespace App\Http\Controllers\Api\Branches;

use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\BranchStatusRequest;
use App\Http\Requests\Branch\DecideBranchClosureRequest;
use App\Http\Requests\Branch\RequestBranchClosureRequest;
use App\Http\Resources\BranchClosureResource;
use App\Models\Branch;
use App\Models\BranchClosure;
use App\Services\Branches\BranchStatus;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Closing a branch (Phase 6, multi-branch decision 3): one user asks with an
 * effective date and a reason, and a different user approves. Refused while
 * the branch is active (suspend it first) or the checklist isn't clear. Every route needs `branches:manage` plus
 * `branches:view_all` (BranchStatusRequest); a hidden branch answers 404.
 */
class BranchClosureController extends Controller
{
    public function __construct(private BranchStatus $status) {}

    #[OA\Get(
        path: '/api/branches/{branch}/closure',
        summary: 'Closure checklist and the waiting request',
        description: 'What still keeps the branch open (open loans, savings and time deposits, money in its cash and bank accounts, pending approvals and requests, assigned users), whether it can close (`can_close`: suspended, not the main office, checklist clear), and the request waiting, if any.',
        tags: ['Branches'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'branch', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: '{data: {branch_id, status, can_close, checklist, request}}'),
            new OA\Response(response: 403, description: 'Needs branches:manage and branches:view_all'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(BranchStatusRequest $request, Branch $branch): JsonResponse
    {
        $checklist = $this->status->checklist($branch);
        $waiting = BranchClosure::query()
            ->with(['requester:id,first_name,last_name', 'decider:id,first_name,last_name'])
            ->where('branch_id', $branch->id)
            ->where('status', BranchClosure::STATUS_PENDING)
            ->first();

        return response()->json(['data' => [
            'branch_id' => $branch->id,
            'status' => $branch->status,
            'can_close' => $this->status->closable($branch)
                && collect($checklist)->every(fn (array $item): bool => $item['clear']),
            'checklist' => $checklist,
            'request' => $waiting === null ? null : new BranchClosureResource($waiting),
        ]]);
    }

    #[OA\Post(
        path: '/api/branches/{branch}/closure',
        summary: 'Ask to close a branch',
        tags: ['Branches'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'branch', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['effective_on', 'reason'],
            properties: [
                new OA\Property(property: 'effective_on', type: 'string', format: 'date', description: 'Today or earlier, not before the opening date'),
                new OA\Property(property: 'reason', type: 'string', maxLength: 500),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'The waiting request'),
            new OA\Response(response: 403, description: 'Needs branches:manage and branches:view_all'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 409, description: 'A request is already waiting'),
            new OA\Response(response: 422, description: 'Refused, in words (still active: suspend it first; checklist not clear; main office; closed)'),
        ],
    )]
    public function store(RequestBranchClosureRequest $request, Branch $branch): JsonResponse
    {
        $closure = $this->status->requestClosure($branch, $request->validated('effective_on'), $request->validated('reason'), $request->user());

        return $this->closure($closure)->response()->setStatusCode(201);
    }

    #[OA\Post(
        path: '/api/branches/{branch}/closure/approve',
        summary: 'Approve the waiting request and close the branch',
        description: 'By a different user from the one who asked. The branch must still be suspended and the checklist is checked again; the branch closes on the effective date.',
        tags: ['Branches'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'branch', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [new OA\Property(property: 'note', type: 'string', maxLength: 500, nullable: true)])),
        responses: [
            new OA\Response(response: 200, description: 'The approved request'),
            new OA\Response(response: 403, description: 'The requester, or missing permissions'),
            new OA\Response(response: 404, description: 'No request waiting'),
            new OA\Response(response: 422, description: 'Refused, in words'),
        ],
    )]
    public function approve(DecideBranchClosureRequest $request, Branch $branch): BranchClosureResource
    {
        return $this->closure($this->status->approveClosure($branch, $request->user(), $request->validated('note')));
    }

    #[OA\Post(
        path: '/api/branches/{branch}/closure/reject',
        summary: 'Reject the waiting request',
        description: 'By a different user from the one who asked, with a note.',
        tags: ['Branches'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'branch', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['note'], properties: [new OA\Property(property: 'note', type: 'string', maxLength: 500)])),
        responses: [
            new OA\Response(response: 200, description: 'The rejected request'),
            new OA\Response(response: 403, description: 'The requester, or missing permissions'),
            new OA\Response(response: 404, description: 'No request waiting'),
        ],
    )]
    public function reject(DecideBranchClosureRequest $request, Branch $branch): BranchClosureResource
    {
        return $this->closure($this->status->rejectClosure($branch, $request->user(), (string) $request->validated('note')));
    }

    #[OA\Delete(
        path: '/api/branches/{branch}/closure',
        summary: 'Cancel the waiting request',
        description: 'Only by the user who asked.',
        tags: ['Branches'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'branch', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The cancelled request'),
            new OA\Response(response: 403, description: 'Not the requester, or missing permissions'),
            new OA\Response(response: 404, description: 'No request waiting'),
        ],
    )]
    public function destroy(BranchStatusRequest $request, Branch $branch): BranchClosureResource
    {
        return $this->closure($this->status->cancelClosure($branch, $request->user()));
    }

    private function closure(BranchClosure $closure): BranchClosureResource
    {
        return new BranchClosureResource($closure->load(['requester:id,first_name,last_name', 'decider:id,first_name,last_name']));
    }
}
