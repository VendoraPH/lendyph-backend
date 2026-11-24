<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Borrower\UpdateBorrowerMembershipRequest;
use App\Http\Resources\BorrowerResource;
use App\Models\Borrower;
use App\Services\Cda\MembershipDetails;
use OpenApi\Attributes as OA;

/**
 * The member page's Membership section (finish run round 2, decision 15).
 * Ending a membership has its own route (Cda\MembershipController).
 */
class BorrowerMembershipController extends Controller
{
    #[OA\Patch(
        path: '/api/borrowers/{id}/membership',
        summary: 'Change a member\'s membership date, member type or membership number',
        description: 'Needs `borrowers:update`; a member outside the caller\'s branches answers 404. Send at least one field. `membership_date` is `Y-m-d`, not after today, and can be changed but not cleared; for a withdrawn or deceased member it can\'t fall after `membership_ended_on`. `member_type` is regular or associate. `membership_number` is stored in capitals without spaces, as members sign in with it: plain keyboard characters, at most 30, a digit, not only zeros, never starting with BRW, and no other member\'s (422 naming the number only); blank sets it back to the member code, and the member\'s own number or code is always accepted. A pending or rejected registration has no membership details (422). One audit row holds the old and new values of what changed; a save that changes nothing writes nothing.',
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'membership_date', type: 'string', format: 'date', example: '2019-03-01'),
            new OA\Property(property: 'member_type', type: 'string', enum: Borrower::MEMBER_TYPES),
            new OA\Property(property: 'membership_number', type: 'string', maxLength: 30, nullable: true, example: 'MEM-0042'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The member, with `membership_date`, `member_type` and `membership_number`'),
            new OA\Response(response: 403, description: 'Missing borrowers:update'),
            new OA\Response(response: 404, description: 'A member outside the caller\'s branches'),
            new OA\Response(response: 422, description: 'Refused in words: a field, a number already taken, a registration not yet a member, or a date after the membership ended'),
        ],
    )]
    public function update(UpdateBorrowerMembershipRequest $request, Borrower $borrower, MembershipDetails $membership): BorrowerResource
    {
        $member = $membership->change($borrower, $request->validated(), $request->user());

        return new BorrowerResource($member->refresh()->load('branch', 'approvedBy', 'rejectedBy'));
    }
}
