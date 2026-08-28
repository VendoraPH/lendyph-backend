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
            new OA\Parameter(name: 'borrower_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'), description: 'Filter to a single borrower'),
            new OA\Parameter(name: 'loan_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'), description: 'Filter to a single loan'),
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

        $filters = request()->validate([
            'search' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            // `min:1` is not cosmetic: 0 is a valid integer that no row can
            // carry, and it used to reach a `when()` that treats it as absent.
            'loan_id' => ['nullable', 'integer', 'min:1'],
            'borrower_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        /**
         * Pulled out as locals so every filter below can be gated on filled() —
         * PRESENCE — rather than on truthiness.
         *
         * `Builder::when()` skips its callback for any falsy condition, and `0`
         * and `'0'` are falsy, so `?borrower_id=0` dropped the scoping filter and
         * answered with the whole repayment book — every member's payment history
         * — plus org-wide `meta.stats`, for a caller who had asked about one
         * member. `/borrowers/0` on the frontend does exactly that, via
         * Number(params.id). Identical to the hole fixed on the loan and
         * collateral lists; this is the same fix carried across.
         */
        $search = $filters['search'] ?? null;
        $status = $filters['status'] ?? null;
        $loanId = $filters['loan_id'] ?? null;
        $borrowerId = $filters['borrower_id'] ?? null;
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        $query = Repayment::with('loan.borrower', 'loan.loanProduct', 'loan.amortizationSchedules', 'receivedByUser', 'voidedByUser')
            ->when(filled($search), function ($q) use ($search) {
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
            ->when(filled($status), fn ($q) => $q->where('status', $status))
            ->when(filled($loanId), fn ($q) => $q->where('loan_id', $loanId))
            ->when(filled($borrowerId), fn ($q) => $q->whereHas('loan', fn ($lq) => $lq->where('borrower_id', $borrowerId)))
            ->when(filled($dateFrom), fn ($q) => $q->whereDate('payment_date', '>=', $dateFrom))
            ->when(filled($dateTo), fn ($q) => $q->whereDate('payment_date', '<=', $dateTo));

        $repayments = $query->latest('payment_date')
            ->paginate(min(max((int) ($filters['per_page'] ?? 15), 1), 100));

        // Attach status count aggregation to the meta envelope so the frontend can
        // render status tabs without a second request. Borrower-scoped on the same
        // filled() gate as the list above — the two must agree, or the tabs report
        // the whole organisation while the rows report one member.
        $stats = Repayment::when(filled($borrowerId), fn ($q) => $q->whereHas('loan', fn ($lq) => $lq->where('borrower_id', $borrowerId)))
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return RepaymentResource::collection($repayments)
            ->additional(['meta' => ['stats' => [
                'posted' => (int) ($stats['posted'] ?? 0),
                'voided' => (int) ($stats['voided'] ?? 0),
            ]]]);
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
            ->with('receivedByUser', 'voidedByUser', 'loan.borrower', 'loan.loanProduct', 'loan.amortizationSchedules')
            ->latest('payment_date')
            ->paginate(min((int) request('per_page', 15), 100));

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

        $repayment->load('receivedByUser', 'loan.borrower', 'loan.loanProduct', 'loan.amortizationSchedules');

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

        $repayment->load('loan.borrower', 'loan.loanProduct', 'loan.amortizationSchedules', 'receivedByUser', 'voidedByUser');

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

        $repayment->load('loan.borrower', 'loan.loanProduct', 'loan.amortizationSchedules', 'receivedByUser', 'voidedByUser');

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

    #[OA\Post(
        path: '/api/loans/{loan}/repayments/preview',
        summary: 'Preview repayment allocation',
        description: 'Runs the real allocation logic inside a rolled-back transaction so the frontend can show a live breakdown of how the amount would split across penalty, overdue interest, current interest, current principal, next interest, next principal, and SCB overpayment — without saving anything.',
        tags: ['Repayments'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'loan', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['amount_paid', 'payment_date'],
                properties: [
                    new OA\Property(property: 'amount_paid', type: 'number', example: 5000.00),
                    new OA\Property(property: 'payment_date', type: 'string', format: 'date', example: '2026-04-15'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Allocation preview'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 404, description: 'Loan not found'),
        ],
    )]
    public function preview(Loan $loan): JsonResponse
    {
        $this->authorize('payments:view');

        $validated = request()->validate([
            'amount_paid' => ['required', 'numeric', 'min:0.01'],
            'payment_date' => ['required', 'date'],
        ]);

        $preview = $this->repaymentService->previewAllocation(
            $loan,
            (float) $validated['amount_paid'],
            $validated['payment_date'],
            request()->user(),
        );

        return response()->json(['data' => $preview]);
    }
}
