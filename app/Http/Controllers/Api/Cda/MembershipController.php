<?php

namespace App\Http\Controllers\Api\Cda;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cda\EndMembershipRequest;
use App\Http\Resources\BorrowerResource;
use App\Models\Borrower;
use App\Services\Cda\MembershipStatus;
use OpenApi\Attributes as OA;

/**
 * Ending a membership (CDA design §1). Reactivating a former member goes
 * through PATCH /borrowers/{id}/reactivate with a reason.
 */
class MembershipController extends Controller
{
    #[OA\Patch(
        path: '/api/borrowers/{id}/end-membership',
        summary: 'End a membership: withdrawn or deceased',
        description: 'Needs `borrowers:update`. `withdrawn` is refused (422, in words) while the member has a loan still being paid, an open savings account or time deposit, or share capital above ₱0.00; `deceased` is always allowed. Audited with the reason. The member\'s branch must be visible to the caller (404 otherwise).',
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['status', 'ended_on', 'reason'], properties: [
            new OA\Property(property: 'status', type: 'string', enum: Borrower::FORMER_MEMBER_STATUSES),
            new OA\Property(property: 'ended_on', type: 'string', format: 'date'),
            new OA\Property(property: 'reason', type: 'string', maxLength: 255),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The member, with `status`, `membership_ended_on` and `membership_end_reason`'),
            new OA\Response(response: 403, description: 'Missing borrowers:update'),
            new OA\Response(response: 422, description: 'Not a current member, a future date, no reason, or something still open'),
        ],
    )]
    public function end(EndMembershipRequest $request, Borrower $borrower, MembershipStatus $membership): BorrowerResource
    {
        $ended = $membership->end($borrower, $request->validated('status'), $request->validated('ended_on'), $request->validated('reason'));

        return new BorrowerResource($ended->refresh()->load('branch', 'approvedBy', 'rejectedBy'));
    }
}
