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

        $filters = request()->validate([
            // `min:1` is not cosmetic: 0 is a valid integer that no row can
            // carry, and it used to reach a `when()` that treats it as absent.
            'borrower_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        /**
         * Pulled out as locals so every filter below can be gated on filled() —
         * PRESENCE — rather than on truthiness.
         *
         * `Builder::when()` skips its callback for any falsy condition, and `0`
         * and `'0'` are falsy, so `?borrower_id=0` dropped the scoping filter and
         * returned the entire share-capital ledger — every member's contribution
         * history — to a caller who had asked about one member. `/borrowers/0` on
         * the frontend does exactly that, via Number(params.id). Same hole, same
         * fix, as the loan, collateral and repayment lists.
         */
        $borrowerId = $filters['borrower_id'] ?? null;
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;
        $search = $filters['search'] ?? null;

        $entries = ShareCapitalLedger::with('borrower', 'createdByUser')
            ->when(filled($borrowerId), fn ($q) => $q->where('borrower_id', $borrowerId))
            ->when(filled($dateFrom), fn ($q) => $q->whereDate('date', '>=', $dateFrom))
            ->when(filled($dateTo), fn ($q) => $q->whereDate('date', '<=', $dateTo))
            ->when(filled($search), function ($q) use ($search) {
                $q->where(function ($q) use ($search) {
                    $q->where('reference', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('borrower', fn ($bq) => $bq->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%"));
                });
            })
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(min(max((int) ($filters['per_page'] ?? 15), 1), 100));

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
            new OA\Response(response: 422, description: 'Validation error. `borrower_id` must belong to a member — a borrower whose registration is still `pending` or was `rejected` is refused here, exactly as on `/pledges/{pledge}/entries`.'),
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
