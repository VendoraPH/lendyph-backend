<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreditScoring\SaveCreditAssessmentRequest;
use App\Models\Loan;
use App\Services\CreditScoring\Api\AssessmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * A loan application's credit assessment (advisory; never changes the loan).
 * `{loan}` binds only loans the caller's branches can see, so another
 * branch's loan answers 404. The application details hold income and
 * expenses, so reading them needs `loans:view` as well as
 * `credit_scoring:view`; saving needs `loans:update`.
 */
class CreditAssessmentController extends Controller
{
    public function __construct(private readonly AssessmentService $assessments) {}

    #[OA\Get(
        path: '/api/credit-scoring/loans/{loan}/assessment',
        summary: "A loan application's credit assessment",
        tags: ['Credit Scoring'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'loan', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The application details, latest assessment score, capacity, flags and decisions'),
            new OA\Response(response: 403, description: 'Missing credit_scoring:view or loans:view'),
            new OA\Response(response: 404, description: 'No such loan, or a loan in another branch'),
        ],
    )]
    public function show(Request $request, Loan $loan): JsonResponse
    {
        $this->authorize('credit_scoring:view');
        $this->authorize('loans:view');

        return response()->json(['success' => true, 'data' => $this->assessments->present($request->user(), $loan)]);
    }

    #[OA\Put(
        path: '/api/credit-scoring/loans/{loan}/assessment',
        summary: "Save a loan application's credit details and assess it",
        tags: ['Credit Scoring'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'loan', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The assessment after the save'),
            new OA\Response(response: 403, description: 'Missing credit_scoring:view or loans:update'),
            new OA\Response(response: 404, description: 'No such loan, or a loan in another branch'),
            new OA\Response(response: 409, description: 'The member is being scored; try again'),
            new OA\Response(response: 422, description: 'A loan past approval, or a bad detail'),
        ],
    )]
    public function update(SaveCreditAssessmentRequest $request, Loan $loan): JsonResponse
    {
        $this->assessments->save($request->user(), $loan, $request->profileFields(), $request->noticeAcknowledged());

        return response()->json(['success' => true, 'data' => $this->assessments->present($request->user(), $loan)]);
    }
}
