<?php

namespace App\Http\Controllers\Api\Branches;

use App\Http\Controllers\Controller;
use App\Http\Requests\Borrower\TransferBorrowerRequest;
use App\Http\Resources\BorrowerResource;
use App\Models\Borrower;
use App\Services\Branches\MemberTransfer;
use OpenApi\Attributes as OA;

/**
 * Moving a member to another branch (Phase 6, multi-branch decision 7). A
 * plain member edit can only fill in a missing branch; every move goes
 * through here, with a reason and an audit row.
 */
class BorrowerTransferController extends Controller
{
    public function __construct(private MemberTransfer $transfer) {}

    #[OA\Post(
        path: '/api/borrowers/{borrower}/transfer',
        summary: 'Transfer a member to another branch',
        description: 'Needs borrowers:transfer and both branches in the caller\'s reach (or branches:view_all). Refused while the member has an open loan (draft to defaulted), savings account, time deposit, portal request or a GCash Cash In not yet paid, and when the destination is suspended or closed. Writes one audit row naming both branches.',
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'borrower', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['branch_id', 'reason'],
            properties: [
                new OA\Property(property: 'branch_id', type: 'integer'),
                new OA\Property(property: 'reason', type: 'string', maxLength: 500),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'The member, in the new branch'),
            new OA\Response(response: 403, description: 'Needs borrowers:transfer'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Refused, in words'),
        ],
    )]
    public function store(TransferBorrowerRequest $request, Borrower $borrower): BorrowerResource
    {
        $moved = $this->transfer->transfer($borrower, (int) $request->validated('branch_id'), $request->validated('reason'), $request->user());

        return new BorrowerResource($moved->load('branch', 'approvedBy', 'rejectedBy'));
    }
}
