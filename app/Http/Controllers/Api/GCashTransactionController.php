<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GCash\PreviewGCashTransactionRequest;
use App\Http\Requests\GCash\StoreGCashTransactionRequest;
use App\Http\Resources\GCashTransactionResource;
use App\Models\GCashTransaction;
use App\Services\Branches\MemberLock;
use App\Services\GCashService;
use Illuminate\Database\Eloquent\Builder;
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
            new OA\Response(
                response: 200,
                description: 'Paginated GCash transactions. A caller without branches:view_all sees their branches\' members\' rows and the walk-in rows recorded in their branches; walk-in rows with no branch need branches:view_all. `meta.totals` covers the same rows.',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GCashTransaction')),
                    new OA\Property(property: 'meta', type: 'object', properties: [
                        new OA\Property(property: 'totals', type: 'object', description: 'Every row the list\'s scope and filters match, `status` included, on every page, not just this one, summed in SQL. Pesos are two-decimal strings. A pending Cash In counts whenever the `status` filter lets it into the list (no filter, or `pending`), and `pending` is the part of each figure still pending. A pending Cash In\'s charge is not income until it is paid, so `charges` can be more than GET /gcash/reports/income `total_income` for the same days.', properties: [
                            new OA\Property(property: 'count', type: 'integer', example: 12),
                            new OA\Property(property: 'amount', type: 'string', description: 'The Amount column: Cash In and Cash Out together.', example: '23000.00'),
                            new OA\Property(property: 'cash_in', type: 'string', description: 'Cash In amounts.', example: '15000.00'),
                            new OA\Property(property: 'cash_out', type: 'string', description: 'Cash Out amounts.', example: '8000.00'),
                            new OA\Property(property: 'charges', type: 'string', description: 'The Charge column, pending Cash Ins included.', example: '240.00'),
                            new OA\Property(property: 'total', type: 'string', description: 'The Total column: a Cash In\'s amount plus its charge, a Cash Out\'s amount less its charge.', example: '23140.00'),
                            new OA\Property(property: 'pending', type: 'object', description: 'The pending (not yet paid) Cash Ins among those rows.', properties: [
                                new OA\Property(property: 'count', type: 'integer', example: 2),
                                new OA\Property(property: 'amount', type: 'string', example: '3000.00'),
                                new OA\Property(property: 'charges', type: 'string', example: '40.00'),
                                new OA\Property(property: 'total', type: 'string', example: '3040.00'),
                            ]),
                        ]),
                    ]),
                ]),
            ),
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

        // The list's rows: its scope and every filter. The page and
        // `meta.totals` both read it, so the totals always describe exactly
        // the rows the list pages through.
        $matching = fn (): Builder => GCashTransaction::query()
            // A member's row follows the member's branch, a walk-in's the
            // branch it was recorded in (GCashTransaction::scopeVisibleTo).
            ->visibleTo($request->user())
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('borrower_id'), fn ($q, $b) => $q->where('borrower_id', $b))
            ->when($request->query('start_date'), fn ($q, $d) => $q->whereDate('transaction_date', '>=', $d))
            ->when($request->query('end_date'), fn ($q, $d) => $q->whereDate('transaction_date', '<=', $d));

        $transactions = $matching()
            ->with(['borrower', 'nonMember', 'transactor'])
            ->latest('transaction_date')
            // Tiebreak on the key so transactions stamped in the same second
            // keep one order across pages. See DeterministicPaginationTest.
            ->orderByDesc('id')
            ->paginate(min($perPage, 100));

        return GCashTransactionResource::collection($transactions)
            ->additional(['meta' => ['totals' => $this->gcash->listTotals($matching())]]);
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
                    new OA\Property(property: 'branch_id', type: 'integer', nullable: true, description: 'Walk-in only: the branch where it is done, one of the caller\'s. Defaults to the caller\'s only branch; required when they hold several; refused (422) when they hold none. branches:view_all may pick any branch; with no key it defaults to their only assigned branch (none when they have no or several), and an explicit null means head office (none). Refused on a member\'s transaction, which follows the member\'s branch. The journal posts in this branch.'),
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
        $tx = MemberLock::whileHeld($request->validated('borrower_id'), fn (): GCashTransaction => $this->gcash->createTransaction($request->validated(), $request->user()));
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
            new OA\Response(response: 404, description: 'Not found, or a row the caller can\'t see (another branch\'s, or a walk-in with no branch without branches:view_all)'),
            new OA\Response(response: 422, description: 'Transaction is not a pending Cash In'),
        ],
    )]
    public function markPaid(GCashTransaction $transaction): JsonResponse
    {
        // Another branch's row answers 404 like a missing one: the
        // `{transaction}` binding is scoped (GCashTransaction::visibleTo).
        $this->authorize('gcash:transact');

        $tx = $this->gcash->markPaid($transaction, request()->user());
        $tx->load(['borrower', 'nonMember', 'transactor', 'paidByUser']);

        return response()->json([
            'message' => 'Transaction marked as paid.',
            'data' => new GCashTransactionResource($tx),
        ]);
    }
}
