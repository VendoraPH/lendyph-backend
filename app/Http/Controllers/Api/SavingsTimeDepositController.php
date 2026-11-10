<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Savings\TimeDeposit\ListTimeDepositsRequest;
use App\Http\Requests\Savings\TimeDeposit\PlaceTimeDepositRequest;
use App\Http\Requests\Savings\TimeDeposit\PreterminateTimeDepositRequest;
use App\Http\Requests\Savings\TimeDeposit\PreterminationQuoteRequest;
use App\Http\Requests\Savings\TimeDeposit\QuoteTimeDepositRequest;
use App\Http\Requests\Savings\TimeDeposit\SettleTimeDepositRequest;
use App\Models\Borrower;
use App\Models\SavingsProduct;
use App\Models\TimeDeposit;
use App\Services\BranchAccess;
use App\Services\Savings\IdempotentWrite;
use App\Services\Savings\SavingsMath;
use App\Services\Savings\TimeDeposits\Placement;
use App\Services\Savings\TimeDeposits\Quote;
use App\Services\Savings\TimeDeposits\Settlement;
use App\Services\Savings\TimeDeposits\TimeDepositPresenter;
use App\Services\Savings\TimeDeposits\TimeDepositScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Time deposits (savings design §2, slice S3): the list and a placement, the
 * server quote, placing, settling at maturity (with rollovers) and breaking
 * one early. Every figure is the server's. A placement in a branch the
 * caller can't see answers 404. Money posts need an `Idempotency-Key`; a
 * cash payout above tier 1 answers 202 with the approval it waits on.
 */
class SavingsTimeDepositController extends Controller
{
    public function __construct(
        private readonly TimeDepositScope $scope,
        private readonly TimeDepositPresenter $presenter,
        private readonly Quote $quote,
        private readonly IdempotentWrite $idempotent,
        private readonly BranchAccess $access,
    ) {}

    #[OA\Get(
        path: '/api/savings/time-deposits',
        summary: 'Time deposits',
        description: 'One page of the placements in the caller\'s branches, newest placement first. `meta.stats` counts each status over the filter without `status` and `search`; `meta.totals` covers the whole filter: `count`, `total_principal` and `maturing_30_days` (the filtered placements that are active and mature from today to 30 days ahead).',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Certificate number, member name or member code', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: TimeDeposit::STATUSES)),
            new OA\Parameter(name: 'product_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'borrower_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The page, with meta.stats and meta.totals'),
            new OA\Response(response: 403, description: 'Missing savings:view, or a branch outside the caller\'s'),
            new OA\Response(response: 422, description: 'An unknown filter value'),
        ],
    )]
    public function index(ListTimeDepositsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $user = $request->user();
        $this->access->authorizeBranchFilter($user, $filters['branch_id'] ?? null);

        $base = $this->scope->query($user)
            ->when(filled($filters['product_id'] ?? null), fn (Builder $q) => $q->where('product_id', (int) $filters['product_id']))
            ->when(filled($filters['branch_id'] ?? null), fn (Builder $q) => $q->where('branch_id', (int) $filters['branch_id']))
            ->when(filled($filters['borrower_id'] ?? null), fn (Builder $q) => $q->where('borrower_id', (int) $filters['borrower_id']));

        $stats = (clone $base)->toBase()->groupBy('status')->selectRaw('status, COUNT(*) AS total')->pluck('total', 'status')
            ->map(fn ($count) => (int) $count)->all();

        $filtered = (clone $base)
            ->when(filled($filters['search'] ?? null), fn (Builder $q) => $this->search($q, (string) $filters['search']))
            ->when(filled($filters['status'] ?? null), fn (Builder $q) => $q->where('status', $filters['status']));

        $sum = (clone $filtered)->toBase()->selectRaw('COUNT(*) AS total_count, COALESCE(SUM(principal), 0) AS total_principal')->first();
        $today = now()->toDateString();
        $maturing = (clone $filtered)->where('status', 'active')
            ->whereBetween('maturity_date', [$today, now()->addDays(30)->toDateString()])
            ->count();

        $page = $this->presenter->withRelations($filtered)
            ->orderByDesc('placement_date')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return response()->json([
            'success' => true,
            'data' => $this->presenter->presentMany($page->getCollection()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'stats' => $stats,
                'totals' => [
                    'count' => (int) $sum->total_count,
                    'total_principal' => SavingsMath::pesos(SavingsMath::centavos((string) $sum->total_principal)),
                    'maturing_30_days' => $maturing,
                ],
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/savings/time-deposits/{timeDeposit}',
        summary: 'A time deposit',
        description: 'The placement with its snapshotted rates, contract figures, settlement and rollover links (`rolled_over_from_id`, `rolled_over_to_id`).',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'timeDeposit', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The placement'),
            new OA\Response(response: 403, description: 'Missing savings:view'),
            new OA\Response(response: 404, description: 'No such placement in the caller\'s branches'),
        ],
    )]
    public function show(Request $request, int $timeDeposit): JsonResponse
    {
        $this->authorize('savings:view');

        return response()->json(['success' => true, 'data' => $this->presenter->present($this->scope->find($request->user(), $timeDeposit))]);
    }

    #[OA\Post(
        path: '/api/savings/time-deposits/quote',
        summary: 'Quote a time deposit',
        description: 'The placement\'s figures, worked out by the server exactly as a placement would be: the rate in force on the placement date for the term, the member withholding tax rate then, interest P × r × days ÷ 365 half up to the centavo. Writes nothing. 422 when the product is not an active time deposit product, the term is not offered or has no rate, or the principal is below the product\'s minimum.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['product_id', 'principal', 'term_days', 'placement_date'], properties: [
            new OA\Property(property: 'borrower_id', type: 'integer'),
            new OA\Property(property: 'product_id', type: 'integer'),
            new OA\Property(property: 'principal', type: 'string', example: '100000.00'),
            new OA\Property(property: 'term_days', type: 'integer', example: 90),
            new OA\Property(property: 'placement_date', type: 'string', format: 'date'),
        ])),
        responses: [
            new OA\Response(response: 200, description: '{annual_rate, withholding_tax_rate, term_days, maturity_date, gross_interest, withholding_tax, net_interest, maturity_amount}'),
            new OA\Response(response: 403, description: 'Missing savings:create'),
            new OA\Response(response: 422, description: 'Invalid fields, or a term, rate or minimum the product refuses'),
        ],
    )]
    public function quote(QuoteTimeDepositRequest $request): JsonResponse
    {
        $data = $request->validated();
        $product = SavingsProduct::query()->findOrFail((int) $data['product_id']);
        $figures = $this->quote->figures($product, SavingsMath::centavos($data['principal']), (int) $data['term_days'], $data['placement_date']);

        return response()->json(['success' => true, 'data' => $this->quote->present($figures)]);
    }

    #[OA\Post(
        path: '/api/savings/time-deposits',
        summary: 'Place a time deposit',
        description: 'Needs an Idempotency-Key. The figures are the quote\'s, snapshotted on the placement. Dated today or yesterday. Paid in cash, by bank transfer, GCash or Maya, or from the member\'s own active savings account in the same branch (`payment_method` savings with `funding_account_id`), never above its available balance. Posts Dr the money account or savings deposits / Cr time deposits.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['borrower_id', 'product_id', 'principal', 'term_days', 'placement_date', 'maturity_instruction', 'payment_method'], properties: [
            new OA\Property(property: 'borrower_id', type: 'integer'),
            new OA\Property(property: 'product_id', type: 'integer'),
            new OA\Property(property: 'principal', type: 'string', example: '100000.00'),
            new OA\Property(property: 'term_days', type: 'integer', example: 90),
            new OA\Property(property: 'placement_date', type: 'string', format: 'date'),
            new OA\Property(property: 'maturity_instruction', type: 'string', enum: TimeDeposit::MATURITY_INSTRUCTIONS),
            new OA\Property(property: 'payment_method', type: 'string', enum: ['cash', 'bank_transfer', 'gcash', 'maya', 'savings']),
            new OA\Property(property: 'reference', type: 'string', nullable: true),
            new OA\Property(property: 'funding_account_id', type: 'integer', nullable: true),
            new OA\Property(property: 'payout_account_id', type: 'integer', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The placement'),
            new OA\Response(response: 403, description: 'Missing savings:create'),
            new OA\Response(response: 409, description: 'Another savings write collided; submit again'),
            new OA\Response(response: 422, description: 'Invalid fields, an inactive member, a term or rate the product refuses, a date outside today and yesterday, or available savings short of the principal'),
        ],
    )]
    public function store(PlaceTimeDepositRequest $request, Placement $placement): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        return $this->idempotent->run($request, function () use ($placement, $data, $user): JsonResponse {
            $deposit = $placement->place($data, $user);

            return response()->json(['success' => true, 'data' => $this->presenter->present($deposit->fresh())], 201);
        });
    }

    #[OA\Post(
        path: '/api/savings/time-deposits/{timeDeposit}/settle',
        summary: 'Settle a time deposit at maturity',
        description: 'Needs an Idempotency-Key. On or after the maturity date (422 before), dated today or yesterday, at the contract\'s figures. `action` payout pays principal and net interest out; rollover_principal places the principal again and pays the interest out; rollover_all places both again, at the rate in force on the maturity date, starting on the settlement date, with `next_instruction` (else the old instruction). 201 with this placement, or the new one on a rollover. A cash payout above tier 1 answers 202 with `{approval}`; a payout into the member\'s savings account (`payout_method` savings) posts at once.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'timeDeposit', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['date', 'action'], properties: [
            new OA\Property(property: 'date', type: 'string', format: 'date'),
            new OA\Property(property: 'action', type: 'string', enum: TimeDeposit::MATURITY_INSTRUCTIONS),
            new OA\Property(property: 'payout_method', type: 'string', enum: ['cash', 'bank_transfer', 'gcash', 'maya', 'savings']),
            new OA\Property(property: 'next_instruction', type: 'string', enum: TimeDeposit::MATURITY_INSTRUCTIONS, nullable: true, description: 'On a rollover: the new placement\'s instruction; the old one\'s when left out'),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The settled placement, or the new placement on a rollover'),
            new OA\Response(response: 202, description: '{approval}: the payout waits for a checker'),
            new OA\Response(response: 403, description: 'Missing savings:transact'),
            new OA\Response(response: 404, description: 'No such placement in the caller\'s branches'),
            new OA\Response(response: 409, description: 'Already settled, or a payout already waits for approval'),
            new OA\Response(response: 422, description: 'Before maturity, a date outside today and yesterday, a payout account that can\'t take it, or a rollover the product refuses'),
        ],
    )]
    public function settle(SettleTimeDepositRequest $request, int $timeDeposit, Settlement $settlement): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();
        $deposit = $this->scope->find($user, $timeDeposit);

        return $this->idempotent->run($request, function () use ($settlement, $deposit, $data, $user): JsonResponse {
            $result = $settlement->requestSettlement($deposit, $data, $user);

            if ($result['approval'] !== null) {
                return response()->json(['success' => true, 'data' => ['approval' => $this->presenter->approval($result['approval'], $result['deposit'])]], 202);
            }

            $shown = $result['rolled'] ?? $result['deposit'];

            return response()->json(['success' => true, 'data' => $this->presenter->present($shown->fresh())], 201);
        });
    }

    #[OA\Get(
        path: '/api/savings/time-deposits/{timeDeposit}/pretermination-quote',
        summary: 'What breaking a time deposit early pays',
        description: 'On `date` (from the placement date to the day before maturity): the regular rate snapshotted at placement for the days held, less the snapshotted withholding tax. `penalty` is "0.00" in v1. Writes nothing.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'timeDeposit', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: '{date, days_held, rate, gross_interest, withholding_tax, penalty, payout}'),
            new OA\Response(response: 403, description: 'Missing savings:close'),
            new OA\Response(response: 404, description: 'No such placement in the caller\'s branches'),
            new OA\Response(response: 409, description: 'Already settled'),
            new OA\Response(response: 422, description: 'A matured placement (settle it instead), or a date before placement or on or after maturity'),
        ],
    )]
    public function preterminationQuote(PreterminationQuoteRequest $request, int $timeDeposit, Settlement $settlement): JsonResponse
    {
        $deposit = $this->scope->find($request->user(), $timeDeposit);
        $settlement->assertCanPreterminate($deposit);

        return response()->json([
            'success' => true,
            'data' => $this->quote->presentPretermination($this->quote->pretermination($deposit, $request->validated('date'))),
        ]);
    }

    #[OA\Post(
        path: '/api/savings/time-deposits/{timeDeposit}/preterminate',
        summary: 'Break a time deposit early',
        description: 'Needs an Idempotency-Key. Before the maturity date, dated today or yesterday, at the pre-termination quote\'s figures, with a reason (audited). 201 with the placement, or 202 with `{approval}` when a cash payout is above tier 1. A payout into the member\'s savings account posts at once.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'timeDeposit', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['date', 'reason', 'payout_method'], properties: [
            new OA\Property(property: 'date', type: 'string', format: 'date'),
            new OA\Property(property: 'reason', type: 'string'),
            new OA\Property(property: 'payout_method', type: 'string', enum: ['cash', 'bank_transfer', 'gcash', 'maya', 'savings']),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The pre-terminated placement'),
            new OA\Response(response: 202, description: '{approval}: the payout waits for a checker'),
            new OA\Response(response: 403, description: 'Missing savings:close'),
            new OA\Response(response: 404, description: 'No such placement in the caller\'s branches'),
            new OA\Response(response: 409, description: 'Already settled, or a payout already waits for approval'),
            new OA\Response(response: 422, description: 'A matured placement or a date on or after maturity (settle it instead), a date outside today and yesterday or before placement, or a payout account that can\'t take it'),
        ],
    )]
    public function preterminate(PreterminateTimeDepositRequest $request, int $timeDeposit, Settlement $settlement): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();
        $deposit = $this->scope->find($user, $timeDeposit);

        return $this->idempotent->run($request, function () use ($settlement, $deposit, $data, $user): JsonResponse {
            $result = $settlement->requestPretermination($deposit, $data, $user);

            if ($result['approval'] !== null) {
                return response()->json(['success' => true, 'data' => ['approval' => $this->presenter->approval($result['approval'], $result['deposit'])]], 202);
            }

            return response()->json(['success' => true, 'data' => $this->presenter->present($result['deposit']->fresh())], 201);
        });
    }

    /**
     * @param  Builder<TimeDeposit>  $query
     * @return Builder<TimeDeposit>
     */
    private function search(Builder $query, string $term): Builder
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($term)).'%';

        return $query->where(fn (Builder $q) => $q
            ->where('certificate_number', 'like', $like)
            ->orWhereIn('borrower_id', Borrower::query()->search($term, false)->select('borrowers.id')));
    }
}
