<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GCash\PreviewGCashTransactionRequest;
use App\Http\Requests\GCash\StoreGCashTransactionRequest;
use App\Http\Resources\GCashTransactionResource;
use App\Models\GCashTransaction;
use App\Services\GCashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class GCashTransactionController extends Controller
{
    public function __construct(private GCashService $gcash) {}

    #[OA\Get(
        path: '/api/gcash/transactions',
        summary: 'List GCash transactions',
        tags: ['GCash'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['cash_in', 'cash_out'])),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'paid', 'completed'])),
            new OA\Parameter(name: 'start_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'end_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'borrower_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated GCash transactions'),
            new OA\Response(response: 403, description: 'Missing gcash:view permission'),
        ],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('gcash:view');

        // `min:1`: `?per_page=0` silently became the default page inside
        // paginate(), and a negative value reached MySQL as `offset` with no
        // `limit` — a 500. The same rule every other list validates.
        $perPage = (int) ($request->validate([
            'per_page' => ['nullable', 'integer', 'min:1'],
        ])['per_page'] ?? 25);

        $transactions = GCashTransaction::query()
            ->with(['borrower', 'nonMember', 'transactor'])
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('borrower_id'), fn ($q, $b) => $q->where('borrower_id', $b))
            ->when($request->query('start_date'), fn ($q, $d) => $q->whereDate('transaction_date', '>=', $d))
            ->when($request->query('end_date'), fn ($q, $d) => $q->whereDate('transaction_date', '<=', $d))
            ->latest('transaction_date')
            // Tiebreak on the key so transactions stamped in the same second
            // keep one order across pages. See DeterministicPaginationTest.
            ->orderByDesc('id')
            ->paginate(min($perPage, 100));

        return GCashTransactionResource::collection($transactions);
    }

    #[OA\Get(
        path: '/api/gcash/transactions/preview',
        summary: 'Quote the charge and total for a GCash transaction',
        description: 'Uses the same fee tiers and rounding as recording the transaction, so the dialog never computes money in the browser. Cash In: total = amount + charge. Cash Out: total = amount - charge. Writes nothing.',
        tags: ['GCash'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'type', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['cash_in', 'cash_out'])),
            new OA\Parameter(name: 'amount', in: 'query', required: true, schema: new OA\Schema(type: 'number'), description: 'Greater than 0, up to 2 decimal places.', example: 1500),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Charge and total for this type and amount',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'object', properties: [
                            new OA\Property(property: 'type', type: 'string', enum: ['cash_in', 'cash_out'], example: 'cash_in'),
                            new OA\Property(property: 'amount', type: 'number', example: 1500.0),
                            new OA\Property(property: 'charge_amount', type: 'number', example: 15.0),
                            new OA\Property(property: 'total_amount', type: 'number', example: 1515.0),
                        ]),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing gcash:transact permission'),
            new OA\Response(response: 422, description: 'Validation error, no matching tier, or a Cash Out whose charge is the whole amount or more'),
        ],
    )]
    public function preview(PreviewGCashTransactionRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->gcash->quote($request->validated('type'), $request->validated('amount')),
        ]);
    }

    #[OA\Post(
        path: '/api/gcash/transactions',
        summary: 'Record a GCash transaction',
        tags: ['GCash'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['borrower_id', 'type', 'amount'],
                properties: [
                    new OA\Property(property: 'borrower_id', type: 'integer'),
                    new OA\Property(property: 'type', type: 'string', enum: ['cash_in', 'cash_out']),
                    new OA\Property(property: 'amount', type: 'number'),
                    new OA\Property(property: 'is_pending', type: 'boolean', description: 'Cash In only — when true, status starts as pending and charge is deferred from income.'),
                    new OA\Property(property: 'remarks', type: 'string', nullable: true, maxLength: 2000),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Transaction recorded'),
            new OA\Response(response: 403, description: 'Missing gcash:transact permission'),
            new OA\Response(response: 409, description: 'Possible duplicate within 60s'),
            new OA\Response(response: 422, description: 'Validation error, no matching tier, or a Cash Out whose charge is the whole amount or more'),
        ],
    )]
    public function store(StoreGCashTransactionRequest $request): JsonResponse
    {
        $tx = $this->gcash->createTransaction($request->validated(), $request->user());
        $tx->load(['borrower', 'nonMember', 'transactor']);

        return (new GCashTransactionResource($tx))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Patch(
        path: '/api/gcash/transactions/{id}/paid',
        summary: 'Mark a pending Cash In transaction as paid',
        tags: ['GCash'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Marked as paid'),
            new OA\Response(response: 403, description: 'Missing gcash:transact permission'),
            new OA\Response(response: 422, description: 'Transaction is not a pending Cash In'),
        ],
    )]
    public function markPaid(GCashTransaction $transaction): JsonResponse
    {
        $this->authorize('gcash:transact');

        $tx = $this->gcash->markPaid($transaction, request()->user());
        $tx->load(['borrower', 'nonMember', 'transactor', 'paidByUser']);

        return response()->json([
            'message' => 'Transaction marked as paid.',
            'data' => new GCashTransactionResource($tx),
        ]);
    }
}
