<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoanAdjustment\ApproveLoanAdjustmentRequest;
use App\Http\Requests\LoanAdjustment\BalanceAdjustmentPreviewRequest;
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

        // `min:1`: `?per_page=0` silently became the default page inside
        // paginate(), and a negative value reached MySQL as `offset` with no
        // `limit` — a 500. The same rule every other list validates.
        $perPage = (int) (request()->validate([
            'per_page' => ['nullable', 'integer', 'min:1'],
        ])['per_page'] ?? 15);

        $adjustments = $loan->adjustments()
            ->with('adjustedByUser', 'approvedByUser')
            ->latest()
            // Tiebreak on the key so adjustments sharing a `created_at` keep
            // one order across pages. See DeterministicPaginationTest.
            ->orderByDesc('id')
            ->paginate(min($perPage, 100));

        return LoanAdjustmentResource::collection($adjustments);
    }

    #[OA\Post(
        path: '/api/loans/{loan}/adjustments',
        summary: 'Create a loan adjustment',
        description: 'A `balance_adjustment` states exactly one of `new_values.adjustment_amount` (a signed change to the principal) or `new_values.target_principal_balance` (the unpaid principal to end on, at least 0, two decimals at most). Given a target, the server works out `adjustment_amount` = target − the loan\'s unpaid principal (GET /loans/{loan}/balance-adjustment-preview shows it first) and stores it with `target_principal_balance` and `outstanding_principal_at_request`; the stored `adjustment_amount` is what is approved and applied. Both or neither is a 422 on `new_values`; `outstanding_principal_at_request` may not be sent.',
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
                    new OA\Property(property: 'new_values', type: 'object', example: ['target_principal_balance' => 50000, 'reason' => 'Agreed settlement']),
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
        path: '/api/loans/{loan}/balance-adjustment-preview',
        summary: 'Preview a balance adjustment to a target principal balance',
        description: 'What a balance adjustment to `target_principal_balance` would be, worked out in centavos from the loan\'s unpaid principal (principal due less paid over its open periods; interest is never part of it): `adjustment_amount` = target − `outstanding_principal`, negative for a reduction. Read-only: writes nothing. Needs loan_adjustments:create; the loan must be released or ongoing (422 on `loan`).',
        tags: ['Loan Adjustments'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'loan', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'target_principal_balance', in: 'query', required: true, schema: new OA\Schema(type: 'number', minimum: 0, example: 50000)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The adjustment', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'outstanding_principal', type: 'number', example: 56800),
                    new OA\Property(property: 'target_principal_balance', type: 'number', example: 50000),
                    new OA\Property(property: 'adjustment_amount', type: 'number', example: -6800),
                    new OA\Property(property: 'resulting_principal_balance', type: 'number', example: 50000),
                ]),
            ])),
            new OA\Response(response: 403, description: 'Missing loan_adjustments:create'),
            new OA\Response(response: 422, description: 'A missing, negative or more-than-two-decimal target, or a loan that cannot be adjusted'),
        ],
    )]
    public function balanceAdjustmentPreview(BalanceAdjustmentPreviewRequest $request, Loan $loan): JsonResponse
    {
        return response()->json(['data' => $this->adjustmentService->balanceAdjustmentPreview(
            $loan,
            (float) $request->validated('target_principal_balance'),
        )]);
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
            new OA\Response(response: 409, description: 'The adjustment was approved, rejected or applied by another request since it was read, or the write deadlocked; nothing was written. Reload and try again'),
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
            new OA\Response(response: 409, description: 'The adjustment was approved, rejected or applied by another request since it was read, or the write deadlocked; nothing was written. Reload and try again'),
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
            new OA\Response(response: 409, description: 'The adjustment was applied by another request at the same time, its loan was closed since it was read, or the write deadlocked; nothing was written. Reload and try again'),
            new OA\Response(response: 422, description: 'Invalid status, or the loan is no longer released or ongoing (on `loan`)'),
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
