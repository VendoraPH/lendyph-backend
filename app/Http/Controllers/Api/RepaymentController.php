<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Repayment\StoreRepaymentRequest;
use App\Http\Requests\Repayment\VoidRepaymentRequest;
use App\Http\Resources\RepaymentResource;
use App\Models\Loan;
use App\Models\Repayment;
use App\Services\RepaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class RepaymentController extends Controller
{
    public function __construct(private RepaymentService $repaymentService) {}

    #[OA\Get(
        path: '/api/repayments',
        summary: 'List all repayments (global)',
        tags: ['Repayments'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['posted', 'voided'])),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated global repayment list'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function listAll(): AnonymousResourceCollection
    {
        $this->authorize('payments:view');

        $repayments = Repayment::with('loan.borrower', 'receivedByUser', 'voidedByUser')
            ->when(request('search'), function ($q, $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('receipt_number', 'like', "%{$search}%")
                        ->orWhereHas('loan', function ($lq) use ($search) {
                            $lq->where('loan_account_number', 'like', "%{$search}%")
                                ->orWhereHas('borrower', fn ($bq) => $bq->where(
                                    DB::raw("CONCAT(first_name, ' ', last_name)"),
                                    'like',
                                    "%{$search}%"
                                ));
                        });
                });
            })
            ->when(request('status'), fn ($q, $s) => $q->where('status', $s))
            ->when(request('date_from'), fn ($q, $d) => $q->whereDate('payment_date', '>=', $d))
            ->when(request('date_to'), fn ($q, $d) => $q->whereDate('payment_date', '<=', $d))
            ->latest('payment_date')
            ->paginate(request('per_page', 15));

        return RepaymentResource::collection($repayments);
    }

    #[OA\Get(
        path: '/api/loans/{loan}/repayments',
        summary: 'List repayments for a loan',
        tags: ['Repayments'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'loan', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated repayment list'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Loan not found'),
        ],
    )]
    public function index(Loan $loan): AnonymousResourceCollection
    {
        $this->authorize('payments:view');

        $repayments = $loan->repayments()
            ->with('receivedByUser', 'voidedByUser')
            ->latest('payment_date')
            ->paginate(request('per_page', 15));

        return RepaymentResource::collection($repayments);
    }

    #[OA\Post(
        path: '/api/loans/{loan}/repayments',
        summary: 'Record a repayment',
        tags: ['Repayments'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'loan', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['payment_date', 'amount_paid'],
                properties: [
                    new OA\Property(property: 'payment_date', type: 'string', format: 'date', example: '2026-04-15'),
                    new OA\Property(property: 'amount_paid', type: 'number', example: 5000.00),
                    new OA\Property(property: 'remarks', type: 'string', nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Repayment recorded'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(StoreRepaymentRequest $request, Loan $loan): JsonResponse
    {
        $repayment = $this->repaymentService->processRepayment(
            $loan,
            (float) $request->amount_paid,
            $request->payment_date,
            $request->user(),
            $request->remarks,
            $request->method,
            $request->reference_number,
        );

        $repayment->load('receivedByUser', 'loan');

        return (new RepaymentResource($repayment))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/repayments/{repayment}',
        summary: 'Show repayment / receipt details',
        tags: ['Repayments'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'repayment', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Repayment details'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(Repayment $repayment): RepaymentResource
    {
        $this->authorize('payments:view');

        $repayment->load('loan', 'receivedByUser', 'voidedByUser');

        return new RepaymentResource($repayment);
    }

    #[OA\Patch(
        path: '/api/repayments/{repayment}/void',
        summary: 'Void a repayment',
        tags: ['Repayments'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'repayment', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['void_reason'],
                properties: [
                    new OA\Property(property: 'void_reason', type: 'string', example: 'Duplicate entry'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Repayment voided'),
            new OA\Response(response: 422, description: 'Already voided or validation error'),
        ],
    )]
    public function void(VoidRepaymentRequest $request, Repayment $repayment): JsonResponse
    {
        $repayment = $this->repaymentService->voidRepayment(
            $repayment,
            $request->void_reason,
            $request->user(),
        );

        $repayment->load('loan', 'receivedByUser', 'voidedByUser');

        return response()->json([
            'message' => 'Repayment voided successfully.',
            'data' => new RepaymentResource($repayment),
        ]);
    }

    #[OA\Get(
        path: '/api/loans/{loan}/summary',
        summary: 'Loan balance summary',
        description: 'Returns outstanding balance, overdue amounts, next due date, and payment totals',
        tags: ['Repayments'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'loan', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Loan summary'),
            new OA\Response(response: 404, description: 'Loan not found'),
        ],
    )]
    public function summary(Loan $loan): JsonResponse
    {
        $this->authorize('loans:view');

        return response()->json([
            'data' => $this->repaymentService->getLoanSummary($loan),
        ]);
    }
}
