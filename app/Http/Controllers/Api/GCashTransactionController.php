<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        $transactions = GCashTransaction::query()
            ->with(['borrower', 'transactor'])
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('borrower_id'), fn ($q, $b) => $q->where('borrower_id', $b))
            ->when($request->query('start_date'), fn ($q, $d) => $q->whereDate('transaction_date', '>=', $d))
            ->when($request->query('end_date'), fn ($q, $d) => $q->whereDate('transaction_date', '<=', $d))
            ->latest('transaction_date')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return GCashTransactionResource::collection($transactions);
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
            new OA\Response(response: 422, description: 'Validation error or no matching tier'),
        ],
    )]
    public function store(StoreGCashTransactionRequest $request): JsonResponse
    {
        $tx = $this->gcash->createTransaction($request->validated(), $request->user());
        $tx->load(['borrower', 'transactor']);

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
        $tx->load(['borrower', 'transactor', 'paidByUser']);

        return response()->json([
            'message' => 'Transaction marked as paid.',
            'data' => new GCashTransactionResource($tx),
        ]);
    }
}
