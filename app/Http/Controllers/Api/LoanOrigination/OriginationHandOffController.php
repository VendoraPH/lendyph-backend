<?php

namespace App\Http\Controllers\Api\LoanOrigination;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoanOrigination\HandOffApplicationRequest;
use App\Models\Loan;
use App\Models\OriginationApplication;
use App\Services\LoanOrigination\ApplicationTransitions;
use App\Services\LoanOrigination\HandOff\ApplicationHandOff;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * The hand-off of an online application into Lendy's own loan and approval
 * code ("Submit for approval", origination decisions 11–13; slice LO-B4).
 * Linking an existing member before it is LO-B3's
 * (Staff\ApplicationController::linkMember()). Applications are branch-scoped
 * by their binding: another branch's answers 404.
 */
class OriginationHandOffController extends Controller
{
    #[OA\Post(
        path: '/api/loan-origination/applications/{application}/submit-for-approval',
        summary: 'Submit an online application for approval (the hand-off)',
        description: 'Links the member or creates a pending registration, then creates the draft loan through the loan code (the product\'s terms, the requested amount and term, a start date of today unless `start_date` is sent) and submits it into the approval chain, in one loan transaction under the member\'s branch lock. An above-capacity or insufficient-information assessment sends it to the Policy Exception chain with the explanation. Needs loan_origination:convert and loans:create, plus borrowers:create for a new member. Only under assessment, with a current assessment that matches the application and every required document verified. From then on the application follows its loan. A repeat answers 409 naming the loan (`loan_id`).',
        tags: ['Loan Origination'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'The application, now For Approval, and its loan'),
            new OA\Response(response: 403, description: 'A permission is missing (in words)'),
            new OA\Response(response: 409, description: 'Already handed off, or the application changed since it was read'),
            new OA\Response(response: 422, description: 'Not ready: status, assessment, documents, member or loan rules (in words)'),
        ],
    )]
    public function store(HandOffApplicationRequest $request, OriginationApplication $application, ApplicationHandOff $handOff): JsonResponse
    {
        $result = $handOff->handOff($application, $request->user(), $request->handOffInput());

        return response()->json([
            'success' => true,
            'message' => "Submitted for approval as loan {$result->loan->application_number}.",
            'data' => [
                ...$this->application($result->application),
                'loan' => [
                    'id' => $result->loan->id,
                    'application_number' => $result->loan->application_number,
                    'status' => $result->loan->status,
                    'policy_exception' => (bool) $result->loan->policy_exception,
                ],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function application(OriginationApplication $application): array
    {
        return [
            'id' => $application->id,
            'reference_number' => $application->reference_number,
            'status' => $application->status,
            'status_label' => ApplicationTransitions::label($application->status),
            'version' => $application->version,
            'borrower_id' => $application->borrower_id,
            'loan_id' => $application->loan_id,
            'loan_application_number' => $application->loan_id === null ? null : Loan::query()->whereKey($application->loan_id)->value('application_number'),
        ];
    }
}
