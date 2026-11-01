<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShareCapital\StoreShareCapitalLedgerRequest;
use App\Http\Resources\ShareCapitalLedgerResource;
use App\Models\ShareCapitalLedger;
use App\Services\LikePattern;
use App\Services\ShareCapitalMemberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

class ShareCapitalLedgerController extends Controller
{
    #[OA\Get(
        path: '/api/share-capital/ledger',
        summary: 'List share capital ledger entries',
        description: 'Each entry carries `running_balance`: the member\'s balance after it, over all of the member\'s entries in posting order (date, then id), whatever the filters. With `borrower_id`, `meta.summary` carries that member\'s `opening_balance` (before `date_from`), `total_credits` and `total_debits` (from `date_from` to `date_to`) and `ending_balance`.',
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
    public function index(ShareCapitalMemberService $members): AnonymousResourceCollection
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

        $entries = $members->withRunningBalance(ShareCapitalLedger::query())
            ->with('borrower', 'createdByUser')
            ->when(filled($borrowerId), fn ($q) => $q->where('borrower_id', $borrowerId))
            ->when(filled($dateFrom), fn ($q) => $q->whereDate('date', '>=', $dateFrom))
            ->when(filled($dateTo), fn ($q) => $q->whereDate('date', '<=', $dateTo))
            ->when(filled($search), function ($q) use ($search) {
                $like = LikePattern::contains($search);

                $q->where(function ($q) use ($like) {
                    $q->where('reference', 'like', $like)
                        ->orWhere('description', 'like', $like)
                        ->orWhereHas('borrower', fn ($bq) => $bq->where('first_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like));
                });
            })
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(min(max((int) ($filters['per_page'] ?? 15), 1), 100));

        $collection = ShareCapitalLedgerResource::collection($entries);

        if (filled($borrowerId)) {
            $collection->additional(['meta' => [
                'summary' => $members->memberPeriod((int) $borrowerId, $dateFrom, $dateTo),
            ]]);
        }

        return $collection;
    }

    #[OA\Get(
        path: '/api/share-capital/members',
        summary: 'Share capital balance per member',
        description: 'One row per member with ledger entries: `total_credits`, `total_debits`, `balance`, `entry_count` and `last_transaction_date`, over every entry. Each `balance` equals the member\'s share capital statement `closing_balance`. `meta.totals` covers the whole book (`member_count`, `total_credits`, `total_debits`, `balance`); `meta.matching_totals` covers the members matching `search`. `search` matches the member code and name, with %, _ and \\ taken literally.',
        tags: ['Share Capital'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['name', 'balance'], default: 'name')),
            new OA\Parameter(name: 'direction', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'asc')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated member balances with book totals'),
            new OA\Response(response: 403, description: 'Missing share_capital:view'),
        ],
    )]
    public function members(ShareCapitalMemberService $members): AnonymousResourceCollection
    {
        $this->authorize('share_capital:view');

        $filters = request()->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'in:name,balance'],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $result = $members->members(
            $filters['search'] ?? null,
            $filters['sort'] ?? 'name',
            $filters['direction'] ?? 'asc',
            min((int) ($filters['per_page'] ?? 25), 100),
        );

        return JsonResource::collection($result['members'])->additional(['meta' => [
            'totals' => $result['totals'],
            'matching_totals' => $result['matching_totals'],
        ]]);
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
