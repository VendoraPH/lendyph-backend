<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShareCapital\StoreShareCapitalLedgerRequest;
use App\Http\Resources\ShareCapitalLedgerResource;
use App\Models\ShareCapitalLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class ShareCapitalLedgerController extends Controller
{
    #[OA\Get(
        path: '/api/share-capital/ledger',
        summary: 'List share capital ledger entries',
        tags: ['Share Capital'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'borrower_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated ledger entries'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('share_capital:view');

        $entries = ShareCapitalLedger::with('borrower', 'createdByUser')
            ->when(request('borrower_id'), fn ($q, $id) => $q->where('borrower_id', $id))
            ->when(request('date_from'), fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when(request('date_to'), fn ($q, $d) => $q->whereDate('date', '<=', $d))
            ->when(request('search'), function ($q, $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('reference', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('borrower', fn ($bq) => $bq->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%"));
                });
            })
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(min((int) request('per_page', 15), 100));

        return ShareCapitalLedgerResource::collection($entries);
    }

    #[OA\Post(
        path: '/api/share-capital/ledger',
        summary: 'Create manual ledger entry',
        tags: ['Share Capital'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['borrower_id', 'date', 'description', 'type', 'amount'],
                properties: [
                    new OA\Property(property: 'borrower_id', type: 'integer'),
                    new OA\Property(property: 'date', type: 'string', format: 'date'),
                    new OA\Property(property: 'description', type: 'string'),
                    new OA\Property(property: 'type', type: 'string', enum: ['credit', 'debit']),
                    new OA\Property(property: 'amount', type: 'number'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Ledger entry created'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(StoreShareCapitalLedgerRequest $request): JsonResponse
    {
        $entry = ShareCapitalLedger::create([
            'borrower_id' => $request->borrower_id,
            'date' => $request->date,
            'description' => $request->description,
            'debit' => $request->type === 'debit' ? $request->amount : 0,
            'credit' => $request->type === 'credit' ? $request->amount : 0,
            'created_by' => $request->user()->id,
        ]);

        $entry->load('borrower', 'createdByUser');

        return (new ShareCapitalLedgerResource($entry))
            ->response()
            ->setStatusCode(201);
    }
}
