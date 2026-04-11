<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Loan\ApproveLoanRequest;
use App\Http\Requests\Loan\RejectLoanRequest;
use App\Http\Requests\Loan\StoreLoanRequest;
use App\Http\Requests\Loan\UpdateLoanRequest;
use App\Http\Resources\AmortizationScheduleResource;
use App\Http\Resources\LoanResource;
use App\Models\Loan;
use App\Services\LoanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class LoanController extends Controller
{
    public function __construct(private LoanService $loanService) {}

    #[OA\Get(
        path: '/api/loans',
        summary: 'List loans',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'borrower_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated loan list'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('loans:view');

        $loans = Loan::with('borrower', 'loanProduct', 'branch', 'createdByUser', 'amortizationSchedules')
            ->when(request('search'), function ($q, $search) {
                $q->where(function ($query) use ($search) {
                    $query->where('application_number', 'like', "%{$search}%")
                        ->orWhere('loan_account_number', 'like', "%{$search}%")
                        ->orWhereHas('borrower', function ($bq) use ($search) {
                            $bq->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('borrower_code', 'like', "%{$search}%");
                        });
                });
            })
            ->when(request('status'), fn ($q, $s) => $q->forStatus($s))
            ->when(request('branch_id'), fn ($q, $b) => $q->forBranch($b))
            ->when(request('borrower_id'), fn ($q, $b) => $q->where('borrower_id', $b))
            ->latest()
            ->paginate(min((int) request('per_page', 15), 100));

        return LoanResource::collection($loans);
    }

    #[OA\Post(
        path: '/api/loans',
        summary: 'Create loan application',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['borrower_id', 'loan_product_id', 'principal_amount', 'start_date'],
                properties: [
                    new OA\Property(property: 'borrower_id', type: 'integer', example: 1),
                    new OA\Property(property: 'co_maker_ids', type: 'array', items: new OA\Items(type: 'integer'), example: [1]),
                    new OA\Property(property: 'loan_product_id', type: 'integer', example: 1),
                    new OA\Property(property: 'principal_amount', type: 'number', example: 50000),
                    new OA\Property(property: 'purpose', type: 'string', example: 'Business expansion'),
                    new OA\Property(property: 'interest_rate', type: 'number', example: 3.0),
                    new OA\Property(property: 'start_date', type: 'string', format: 'date', example: '2026-04-01'),
                    new OA\Property(
                        property: 'deductions',
                        type: 'array',
                        items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'name', type: 'string', example: 'Processing Fee'),
                                new OA\Property(property: 'amount', type: 'number', example: 2),
                                new OA\Property(property: 'type', type: 'string', enum: ['fixed', 'percentage'], example: 'percentage'),
                            ],
                        ),
                    ),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Loan application created'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(StoreLoanRequest $request): JsonResponse
    {
        $loan = $this->loanService->createLoan($request->validated(), $request->user());
        $loan->load('borrower', 'loanProduct', 'branch', 'coMakers', 'createdByUser');

        return (new LoanResource($loan))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/loans/{id}',
        summary: 'Show loan',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Loan details'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(Loan $loan): LoanResource
    {
        $this->authorize('loans:view');

        $loan->load(
            'borrower', 'loanProduct', 'branch', 'coMakers',
            'approvedByUser', 'releasedByUser', 'rejectedByUser',
            'createdByUser', 'accountOfficer', 'amortizationSchedules',
        );

        return new LoanResource($loan);
    }

    #[OA\Put(
        path: '/api/loans/{id}',
        summary: 'Update loan',
        description: 'Update loan application (only if draft or for_review)',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent),
        responses: [
            new OA\Response(response: 200, description: 'Loan updated'),
            new OA\Response(response: 422, description: 'Validation error or not editable'),
        ],
    )]
    public function update(UpdateLoanRequest $request, Loan $loan): LoanResource
    {
        $loan = $this->loanService->updateLoan($loan, $request->validated());
        $loan->load('borrower', 'loanProduct', 'branch', 'coMakers');

        return new LoanResource($loan);
    }

    #[OA\Delete(
        path: '/api/loans/{id}',
        summary: 'Delete loan',
        description: 'Delete loan application (only if draft)',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Loan deleted'),
            new OA\Response(response: 422, description: 'Cannot delete'),
        ],
    )]
    public function destroy(Loan $loan): JsonResponse
    {
        $this->authorize('loans:void');

        if ($loan->status !== 'draft') {
            return response()->json(['message' => 'Only draft loans can be deleted.'], 422);
        }

        $loan->delete();

        return response()->json(['message' => 'Loan deleted successfully.']);
    }

    #[OA\Patch(
        path: '/api/loans/{id}/submit',
        summary: 'Submit loan for review',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Loan submitted for review'),
            new OA\Response(response: 422, description: 'Invalid status transition'),
        ],
    )]
    public function submit(Loan $loan): JsonResponse
    {
        $this->authorize('loans:update');

        $this->loanService->submitForReview($loan);

        return response()->json(['message' => 'Loan submitted for review.', 'data' => new LoanResource($loan)]);
    }

    #[OA\Patch(
        path: '/api/loans/{id}/approve',
        summary: 'Approve loan',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'approval_remarks', type: 'string'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Loan approved'),
            new OA\Response(response: 422, description: 'Invalid status transition'),
        ],
    )]
    public function approve(ApproveLoanRequest $request, Loan $loan): JsonResponse
    {
        $this->loanService->approve($loan, $request->user(), $request->approval_remarks);
        $loan->load('approvedByUser');

        return response()->json(['message' => 'Loan approved.', 'data' => new LoanResource($loan)]);
    }

    #[OA\Patch(
        path: '/api/loans/{id}/reject',
        summary: 'Reject loan',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'approval_remarks', type: 'string'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Loan rejected'),
            new OA\Response(response: 422, description: 'Invalid status transition'),
        ],
    )]
    public function reject(RejectLoanRequest $request, Loan $loan): JsonResponse
    {
        $this->loanService->reject($loan, $request->user(), $request->approval_remarks);

        return response()->json(['message' => 'Loan rejected.', 'data' => new LoanResource($loan)]);
    }

    #[OA\Patch(
        path: '/api/loans/{id}/release',
        summary: 'Release loan',
        description: 'Release an approved loan — generates loan account number and amortization schedule',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Loan released'),
            new OA\Response(response: 422, description: 'Invalid status transition'),
        ],
    )]
    public function release(Loan $loan): JsonResponse
    {
        $this->authorize('loans:release');

        $loan = $this->loanService->release($loan, auth()->user());
        $loan->load('borrower', 'loanProduct', 'branch', 'coMakers',
            'approvedByUser', 'releasedByUser', 'amortizationSchedules');

        return response()->json(['message' => 'Loan released successfully.', 'data' => new LoanResource($loan)]);
    }

    #[OA\Patch(
        path: '/api/loans/{id}/void',
        summary: 'Void loan',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Loan voided'),
            new OA\Response(response: 422, description: 'Cannot void'),
        ],
    )]
    public function void(Loan $loan): JsonResponse
    {
        $this->authorize('loans:void');

        $this->loanService->voidLoan($loan);

        return response()->json(['message' => 'Loan voided.', 'data' => new LoanResource($loan)]);
    }

    #[OA\Get(
        path: '/api/loans/{id}/amortization-preview',
        summary: 'Preview amortization schedule',
        description: 'Compute and return amortization schedule without persisting',
        tags: ['Amortization Schedule'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Amortization schedule preview'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function amortizationPreview(Loan $loan): JsonResponse
    {
        $this->authorize('loans:view');

        $schedule = $this->loanService->buildAmortizationPreview($loan);

        return response()->json(['data' => $schedule]);
    }

    #[OA\Get(
        path: '/api/loans/{id}/amortization-schedule',
        summary: 'View persisted amortization schedule with payment tracking',
        description: 'Returns the saved amortization schedule with beginning balance, paid amounts, penalties, and status per installment. Includes summary totals.',
        tags: ['Amortization Schedule'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Amortization schedule with payment tracking'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Loan has no schedule yet'),
        ],
    )]
    public function amortizationSchedule(Loan $loan): JsonResponse
    {
        $this->authorize('loans:view');

        $schedules = $loan->amortizationSchedules;

        if ($schedules->isEmpty()) {
            return response()->json([
                'message' => 'No amortization schedule found. Loan may not have been released yet.',
            ], 422);
        }

        $summary = [
            'total_principal' => round($schedules->sum(fn ($s) => (float) $s->principal_due), 2),
            'total_interest' => round($schedules->sum(fn ($s) => (float) $s->interest_due), 2),
            'total_penalty' => round($schedules->sum(fn ($s) => (float) $s->penalty_amount), 2),
            'total_due' => round($schedules->sum(fn ($s) => (float) $s->total_due + (float) $s->penalty_amount), 2),
            'total_principal_paid' => round($schedules->sum(fn ($s) => (float) $s->principal_paid), 2),
            'total_interest_paid' => round($schedules->sum(fn ($s) => (float) $s->interest_paid), 2),
            'total_penalty_paid' => round($schedules->sum(fn ($s) => (float) $s->penalty_paid), 2),
            'total_paid' => round($schedules->sum(fn ($s) => (float) $s->principal_paid + (float) $s->interest_paid + (float) $s->penalty_paid), 2),
            'periods_total' => $schedules->count(),
            'periods_paid' => $schedules->where('status', 'paid')->count(),
            'periods_partial' => $schedules->where('status', 'partial')->count(),
            'periods_overdue' => $schedules->where('status', 'overdue')->count(),
            'periods_pending' => $schedules->where('status', 'pending')->count(),
        ];

        return response()->json([
            'data' => AmortizationScheduleResource::collection($schedules),
            'summary' => $summary,
        ]);
    }
}
