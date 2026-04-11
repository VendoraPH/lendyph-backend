<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoanAdjustment\ApproveLoanAdjustmentRequest;
use App\Http\Requests\LoanAdjustment\StoreLoanAdjustmentRequest;
use App\Http\Resources\LoanAdjustmentResource;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Services\LoanAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class LoanAdjustmentController extends Controller
{
    public function __construct(private LoanAdjustmentService $adjustmentService) {}

    #[OA\Get(
        path: '/api/loans/{loan}/adjustments',
        summary: 'List adjustments for a loan',
        tags: ['Loan Adjustments'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'loan', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Loan adjustments list'),
        ],
    )]
    public function index(Loan $loan): AnonymousResourceCollection
    {
        $this->authorize('loan_adjustments:view');

        $adjustments = $loan->adjustments()
            ->with('adjustedByUser', 'approvedByUser')
            ->latest()
            ->paginate(min((int) request('per_page', 15), 100));

        return LoanAdjustmentResource::collection($adjustments);
    }

    #[OA\Post(
        path: '/api/loans/{loan}/adjustments',
        summary: 'Create a loan adjustment',
        tags: ['Loan Adjustments'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'loan', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['adjustment_type', 'new_values'],
                properties: [
                    new OA\Property(property: 'adjustment_type', type: 'string', enum: ['restructure', 'penalty_waiver', 'balance_adjustment', 'term_extension']),
                    new OA\Property(property: 'new_values', type: 'object'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'remarks', type: 'string', nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Adjustment created'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(StoreLoanAdjustmentRequest $request, Loan $loan): JsonResponse
    {
        $adjustment = $this->adjustmentService->createAdjustment(
            $loan,
            $request->validated(),
            $request->user(),
        );

        $adjustment->load('adjustedByUser', 'loan');

        return (new LoanAdjustmentResource($adjustment))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/loan-adjustments/{loanAdjustment}',
        summary: 'Show adjustment details',
        tags: ['Loan Adjustments'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'loanAdjustment', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Adjustment details'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(LoanAdjustment $loanAdjustment): LoanAdjustmentResource
    {
        $this->authorize('loan_adjustments:view');

        $loanAdjustment->load('loan', 'adjustedByUser', 'approvedByUser');

        return new LoanAdjustmentResource($loanAdjustment);
    }

    #[OA\Patch(
        path: '/api/loan-adjustments/{loanAdjustment}/approve',
        summary: 'Approve an adjustment',
        tags: ['Loan Adjustments'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'loanAdjustment', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [new OA\Property(property: 'remarks', type: 'string', nullable: true)],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Adjustment approved'),
            new OA\Response(response: 422, description: 'Invalid status'),
        ],
    )]
    public function approve(ApproveLoanAdjustmentRequest $request, LoanAdjustment $loanAdjustment): JsonResponse
    {
        $adjustment = $this->adjustmentService->approveAdjustment(
            $loanAdjustment,
            $request->user(),
            $request->remarks,
        );

        return response()->json([
            'message' => 'Adjustment approved.',
            'data' => new LoanAdjustmentResource($adjustment),
        ]);
    }

    #[OA\Patch(
        path: '/api/loan-adjustments/{loanAdjustment}/reject',
        summary: 'Reject an adjustment',
        tags: ['Loan Adjustments'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'loanAdjustment', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [new OA\Property(property: 'remarks', type: 'string', nullable: true)],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Adjustment rejected'),
            new OA\Response(response: 422, description: 'Invalid status'),
        ],
    )]
    public function reject(ApproveLoanAdjustmentRequest $request, LoanAdjustment $loanAdjustment): JsonResponse
    {
        $adjustment = $this->adjustmentService->rejectAdjustment(
            $loanAdjustment,
            $request->user(),
            $request->remarks,
        );

        return response()->json([
            'message' => 'Adjustment rejected.',
            'data' => new LoanAdjustmentResource($adjustment),
        ]);
    }

    #[OA\Patch(
        path: '/api/loan-adjustments/{loanAdjustment}/apply',
        summary: 'Apply an approved adjustment to the loan',
        tags: ['Loan Adjustments'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'loanAdjustment', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Adjustment applied'),
            new OA\Response(response: 422, description: 'Invalid status'),
        ],
    )]
    public function apply(LoanAdjustment $loanAdjustment): JsonResponse
    {
        $this->authorize('loan_adjustments:approve');

        $adjustment = $this->adjustmentService->applyAdjustment($loanAdjustment);
        $adjustment->load('loan.amortizationSchedules');

        return response()->json([
            'message' => 'Adjustment applied successfully.',
            'data' => new LoanAdjustmentResource($adjustment),
        ]);
    }
}
