<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Savings\Account\AccountTransactionsRequest;
use App\Http\Requests\Savings\Account\ChangeSavingsStatusRequest;
use App\Http\Requests\Savings\Account\CloseSavingsAccountRequest;
use App\Http\Requests\Savings\Account\ClosingQuoteRequest;
use App\Http\Requests\Savings\Account\ListSavingsAccountsRequest;
use App\Http\Requests\Savings\Account\OpenSavingsAccountRequest;
use App\Http\Requests\Savings\Account\SavingsMoneyRequest;
use App\Http\Requests\Savings\Account\TransferSavingsRequest;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Services\BranchAccess;
use App\Services\GeneratedNumberWrite;
use App\Services\LikePattern;
use App\Services\Savings\Api\AccountCloser;
use App\Services\Savings\Api\AccountOpener;
use App\Services\Savings\Api\AccountScope;
use App\Services\Savings\Api\Presenter;
use App\Services\Savings\Api\StatusChanger;
use App\Services\Savings\Api\Teller;
use App\Services\Savings\IdempotentWrite;
use App\Services\Savings\SavingsMath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/**
 * Savings accounts and the counter (design §2, slice S2): the list, opening,
 * an account and its ledger, deposits, withdrawals, transfers, the closing
 * quote, closing, and freezing or reactivating.
 *
 * Every money post needs an `Idempotency-Key` header (a UUID): a retry with
 * the same key and body answers the first response again
 * (`Idempotent-Replayed: true`) and posts nothing. Another branch's account
 * answers 404. Money is a two-decimal string.
 */
class SavingsAccountController extends Controller
{
    public function __construct(
        private readonly AccountScope $scope,
        private readonly Presenter $presenter,
        private readonly IdempotentWrite $idempotent,
    ) {}

    #[OA\Get(
        path: '/api/savings/accounts',
        summary: 'Savings accounts',
        description: 'Newest first, in the caller\'s branches. `meta.stats` counts accounts per status over every filter but `status`; `meta.totals` (`account_count`, `total_balance`) covers the whole filter, not just the page.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Account number, member code or name', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: SavingsAccount::STATUSES)),
            new OA\Parameter(name: 'product_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'borrower_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'One page of accounts'),
            new OA\Response(response: 403, description: 'Missing savings:view, or a branch outside the caller\'s'),
            new OA\Response(response: 422, description: 'An unknown filter value'),
        ],
    )]
    public function index(ListSavingsAccountsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $user = $request->user();
        app(BranchAccess::class)->authorizeBranchFilter($user, $filters['branch_id'] ?? null);
        $search = trim((string) ($filters['search'] ?? ''));

        $filtered = fn (): Builder => $this->scope->accounts($user)
            ->when(isset($filters['product_id']), fn ($q) => $q->where('product_id', $filters['product_id']))
            ->when(isset($filters['branch_id']), fn ($q) => $q->where('branch_id', $filters['branch_id']))
            ->when(isset($filters['borrower_id']), fn ($q) => $q->where('borrower_id', $filters['borrower_id']))
            ->when($search !== '', function (Builder $q) use ($search): void {
                $like = LikePattern::contains($search);
                $q->where(fn (Builder $w) => $w
                    ->where('account_number', 'like', $like)
                    ->orWhereHas('borrower', fn (Builder $b) => $b
                        ->where('borrower_code', 'like', $like)
                        ->orWhere('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhereRaw("CONCAT_WS(' ', first_name, middle_name, last_name) LIKE ?", [$like])
                        ->orWhereRaw("CONCAT_WS(' ', first_name, last_name) LIKE ?", [$like])));
            });

        $matching = fn (): Builder => $filtered()->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']));
        $page = $matching()->orderByDesc('id')->paginate(Presenter::perPage($filters['per_page'] ?? null));
        $total = $matching()->toBase()->selectRaw('COUNT(*) AS n, COALESCE(SUM(ledger_balance), 0) AS balance')->first();

        return Presenter::page($page, $this->presenter->accounts(collect($page->items())), [
            'stats' => Presenter::stats($filtered(), 'status', SavingsAccount::STATUSES),
            'totals' => [
                'account_count' => (int) $total->n,
                'total_balance' => SavingsMath::pesos(SavingsMath::signedCentavos((string) $total->balance)),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/savings/accounts',
        summary: 'Open a savings account',
        description: 'For an active member in the caller\'s branches, on an active regular product, with an opening deposit of at least the product\'s minimum (posted Dr settlement / Cr 2050). Needs an `Idempotency-Key` header (UUID). `opened_at` is today or yesterday (Manila).',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['borrower_id', 'product_id', 'opened_at', 'initial_deposit'], properties: [
            new OA\Property(property: 'borrower_id', type: 'integer'),
            new OA\Property(property: 'product_id', type: 'integer'),
            new OA\Property(property: 'opened_at', type: 'string', format: 'date'),
            new OA\Property(property: 'initial_deposit', type: 'string', example: '500.00'),
            new OA\Property(property: 'payment_method', type: 'string', enum: OpenSavingsAccountRequest::PAYMENT_METHODS),
            new OA\Property(property: 'reference', type: 'string', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The account'),
            new OA\Response(response: 403, description: 'Missing savings:create'),
            new OA\Response(response: 409, description: 'The member already has an open account on this product, or a number collided five times'),
            new OA\Response(response: 422, description: 'The member isn\'t active, the product is off or a time deposit, the deposit is below the minimum, or no Idempotency-Key'),
        ],
    )]
    public function store(OpenSavingsAccountRequest $request, AccountOpener $opener): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        return $this->idempotent->run($request, fn (): JsonResponse => response()->json([
            'success' => true,
            'data' => $this->presenter->account($opener->open($data, $user)),
        ], 201));
    }

    #[OA\Get(
        path: '/api/savings/accounts/{account}',
        summary: 'A savings account',
        description: 'With the server\'s `balance`, `hold_amount`, `held_for_requests` (the part of `hold_amount` held for portal withdrawal requests from members), `pending_amount` (outflows waiting for approval), `available_balance`, `accrued_interest` (since the last crediting, not yet credited) and `interest_credited_through`.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'account', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The account'),
            new OA\Response(response: 403, description: 'Missing savings:view'),
            new OA\Response(response: 404, description: 'No such account in the caller\'s branches'),
        ],
    )]
    public function show(Request $request, int $account): JsonResponse
    {
        $this->authorize('savings:view');

        return response()->json(['success' => true, 'data' => $this->presenter->account($this->scope->account($request->user(), $account))]);
    }

    #[OA\Get(
        path: '/api/savings/accounts/{account}/transactions',
        summary: 'An account\'s ledger',
        description: 'Newest first. `meta.opening_balance` is the balance before `date_from`; `meta.totals` (`debit`, `credit`) covers the whole filter.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'account', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: SavingsTransaction::TYPES)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'One page of the ledger'),
            new OA\Response(response: 403, description: 'Missing savings:view'),
            new OA\Response(response: 404, description: 'No such account in the caller\'s branches'),
            new OA\Response(response: 422, description: 'A bad filter'),
        ],
    )]
    public function transactions(AccountTransactionsRequest $request, int $account): JsonResponse
    {
        $filters = $request->validated();
        $base = fn (): Builder => SavingsTransaction::query()->where('account_id', $request->record()->getKey());
        $matching = fn (): Builder => $base()
            ->when(isset($filters['date_from']), fn ($q) => $q->where('date', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn ($q) => $q->where('date', '<=', $filters['date_to']))
            ->when(isset($filters['type']), fn ($q) => $q->where('type', $filters['type']));

        $page = $matching()->orderByDesc('date')->orderByDesc('id')->paginate(Presenter::perPage($filters['per_page'] ?? null));
        $totals = $this->sums($matching());
        $opening = isset($filters['date_from']) ? $this->sums($base()->where('date', '<', $filters['date_from'])) : ['credit' => 0, 'debit' => 0];

        return Presenter::page($page, $this->presenter->transactions(collect($page->items())), [
            'opening_balance' => SavingsMath::pesos($opening['credit'] - $opening['debit']),
            'totals' => ['debit' => SavingsMath::pesos($totals['debit']), 'credit' => SavingsMath::pesos($totals['credit'])],
        ]);
    }

    #[OA\Post(
        path: '/api/savings/accounts/{account}/deposits',
        summary: 'Deposit',
        description: 'Posts the deposit and its journal (Dr settlement / Cr 2050). Refused (422) on a frozen or closed account, in a closed period, or dated other than today or yesterday; a dormant account becomes active (audited). Needs an `Idempotency-Key` header.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'account', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['amount', 'date', 'payment_method'], properties: [
            new OA\Property(property: 'amount', type: 'string', example: '1000.00'),
            new OA\Property(property: 'date', type: 'string', format: 'date', description: 'Today or yesterday (Manila)'),
            new OA\Property(property: 'payment_method', type: 'string', enum: OpenSavingsAccountRequest::PAYMENT_METHODS),
            new OA\Property(property: 'reference', type: 'string', nullable: true),
            new OA\Property(property: 'remarks', type: 'string', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The transaction'),
            new OA\Response(response: 403, description: 'Missing savings:transact'),
            new OA\Response(response: 404, description: 'No such account in the caller\'s branches'),
            new OA\Response(response: 409, description: 'Another write got there first'),
            new OA\Response(response: 422, description: 'Refused, in words'),
        ],
    )]
    public function deposit(SavingsMoneyRequest $request, Teller $teller, int $account): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        return $this->idempotent->run($request, fn (): JsonResponse => response()->json([
            'success' => true,
            'data' => $this->presenter->transaction($teller->deposit($account, $data, $user)),
        ], 201));
    }

    #[OA\Post(
        path: '/api/savings/accounts/{account}/withdrawals',
        summary: 'Withdraw',
        description: 'Up to the available balance, from an active account. Up to tier 1 it posts (201, the transaction, Dr 2050 / Cr settlement); above it, it waits for approval and reserves the amount (202, `{approval}`). Needs an `Idempotency-Key` header.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'account', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['amount', 'date', 'payment_method'], properties: [
            new OA\Property(property: 'amount', type: 'string', example: '1000.00'),
            new OA\Property(property: 'date', type: 'string', format: 'date', description: 'Today or yesterday (Manila)'),
            new OA\Property(property: 'payment_method', type: 'string', enum: OpenSavingsAccountRequest::PAYMENT_METHODS),
            new OA\Property(property: 'reference', type: 'string', nullable: true),
            new OA\Property(property: 'remarks', type: 'string', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The transaction'),
            new OA\Response(response: 202, description: '`{approval}`: sent for approval'),
            new OA\Response(response: 403, description: 'Missing savings:transact'),
            new OA\Response(response: 404, description: 'No such account in the caller\'s branches'),
            new OA\Response(response: 409, description: 'Another write got there first'),
            new OA\Response(response: 422, description: 'Not active, above the available balance, or a date or period refused'),
        ],
    )]
    public function withdraw(SavingsMoneyRequest $request, Teller $teller, int $account): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        return $this->idempotent->run($request, function () use ($teller, $account, $data, $user): JsonResponse {
            $result = $teller->withdraw($account, $data, $user);

            return $result['approval'] !== null
                ? response()->json(['success' => true, 'data' => ['approval' => $this->presenter->approval($result['approval'])]], 202)
                : response()->json(['success' => true, 'data' => $this->presenter->transaction($result['transaction'])], 201);
        });
    }

    #[OA\Post(
        path: '/api/savings/accounts/{account}/transfers',
        summary: 'Transfer to the member\'s own account',
        description: 'To another account of the same member in the same branch (an active or dormant one; dormant becomes active). Two linked legs, no journal, no approval tier. Up to the available balance. Needs an `Idempotency-Key` header.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'account', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['to_account_id', 'amount', 'date'], properties: [
            new OA\Property(property: 'to_account_id', type: 'integer'),
            new OA\Property(property: 'amount', type: 'string', example: '1000.00'),
            new OA\Property(property: 'date', type: 'string', format: 'date'),
            new OA\Property(property: 'remarks', type: 'string', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 201, description: '`{transfer_out, transfer_in}`'),
            new OA\Response(response: 403, description: 'Missing savings:transact'),
            new OA\Response(response: 404, description: 'No such account in the caller\'s branches'),
            new OA\Response(response: 409, description: 'Another write got there first'),
            new OA\Response(response: 422, description: 'Another member\'s or branch\'s account, a frozen or closed one, or above the available balance'),
        ],
    )]
    public function transfer(TransferSavingsRequest $request, Teller $teller, int $account): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        return $this->idempotent->run($request, function () use ($teller, $account, $data, $user): JsonResponse {
            $legs = $teller->transfer($account, $data, $user);

            return response()->json(['success' => true, 'data' => [
                'transfer_out' => $this->presenter->transaction($legs['transfer_out']),
                'transfer_in' => $this->presenter->transaction($legs['transfer_in']),
            ]], 201);
        });
    }

    #[OA\Get(
        path: '/api/savings/accounts/{account}/closing-quote',
        summary: 'What closing today credits and pays',
        description: '`{balance, accrued_interest, withholding_tax, payout_amount, can_close, blockers[]}` for closing on `date` (today by default; today or yesterday, as the close): the interest accrued through the day before and its tax, by the interest run\'s own calculation. `blockers` says, in words, why it can\'t close.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'account', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The quote'),
            new OA\Response(response: 403, description: 'Missing savings:close'),
            new OA\Response(response: 404, description: 'No such account in the caller\'s branches'),
            new OA\Response(response: 422, description: 'A date other than today or yesterday'),
        ],
    )]
    public function closingQuote(ClosingQuoteRequest $request, AccountCloser $closer, int $account): JsonResponse
    {
        /** @var SavingsAccount $found */
        $found = $request->record();

        return response()->json(['success' => true, 'data' => $closer->quote($found, $request->validated('date'))]);
    }

    #[OA\Post(
        path: '/api/savings/accounts/{account}/close',
        summary: 'Close an account',
        description: 'Credits the accrued interest (less its tax), then pays the whole balance out. 201 with the closed account, or 202 with `{approval}` when the payout is above tier 1. Refused (422) while frozen, holding money, with a request waiting for approval, in an unposted interest run, or already closed. Needs an `Idempotency-Key` header.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'account', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['date', 'reason', 'payout_method'], properties: [
            new OA\Property(property: 'date', type: 'string', format: 'date'),
            new OA\Property(property: 'reason', type: 'string'),
            new OA\Property(property: 'payout_method', type: 'string', enum: OpenSavingsAccountRequest::PAYMENT_METHODS),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The closed account'),
            new OA\Response(response: 202, description: '`{approval}`: sent for approval'),
            new OA\Response(response: 403, description: 'Missing savings:close'),
            new OA\Response(response: 404, description: 'No such account in the caller\'s branches'),
            new OA\Response(response: 409, description: 'Another write got there first'),
            new OA\Response(response: 422, description: 'It can\'t close, in words'),
        ],
    )]
    public function close(CloseSavingsAccountRequest $request, AccountCloser $closer, int $account): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        return $this->idempotent->run($request, function () use ($closer, $account, $data, $user): JsonResponse {
            $result = $closer->close($account, $data, $user);

            return $result['approval'] !== null
                ? response()->json(['success' => true, 'data' => ['approval' => $this->presenter->approval($result['approval'])]], 202)
                : response()->json(['success' => true, 'data' => $this->presenter->account($result['account'])], 201);
        });
    }

    #[OA\Post(
        path: '/api/savings/accounts/{account}/status',
        summary: 'Freeze, unfreeze or reactivate',
        description: '`frozen` freezes an active or dormant account; `active` unfreezes a frozen one (back to dormant when it was dormant before). `active` on a dormant account asks for its reactivation: 202 with `{approval}` (kind `reactivation`, one checker who isn\'t the maker); once approved it becomes active, counting as member activity that day. Audited with the reason. A closed account keeps its status (422). A reactivation already waiting, or a lock wait, answers 409.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'account', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['status', 'reason'], properties: [
            new OA\Property(property: 'status', type: 'string', enum: ['frozen', 'active']),
            new OA\Property(property: 'reason', type: 'string'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The account'),
            new OA\Response(response: 202, description: '`{approval}`: a reactivation sent for approval'),
            new OA\Response(response: 403, description: 'Missing savings:close'),
            new OA\Response(response: 404, description: 'No such account in the caller\'s branches'),
            new OA\Response(response: 422, description: 'Already in that status, or closed'),
        ],
    )]
    public function status(ChangeSavingsStatusRequest $request, StatusChanger $changer, int $account): JsonResponse
    {
        $data = $request->validated();
        $result = GeneratedNumberWrite::run(
            fn (): array => DB::transaction(fn (): array => $changer->change($account, $data['status'], $data['reason'], $request->user())),
            GeneratedNumberWrite::SAVINGS_CONFLICT,
        );

        return $result['approval'] !== null
            ? response()->json(['success' => true, 'message' => 'Reactivation sent for approval. The account stays dormant until a checker approves it.', 'data' => ['approval' => $this->presenter->approval($result['approval'])]], 202)
            : response()->json(['success' => true, 'data' => $this->presenter->account($result['account'])]);
    }

    /**
     * @param  Builder<SavingsTransaction>  $query
     * @return array{credit: int, debit: int}
     */
    private function sums(Builder $query): array
    {
        $row = $query->toBase()->reorder()->selectRaw('COALESCE(SUM(credit), 0) AS credit, COALESCE(SUM(debit), 0) AS debit')->first();

        return ['credit' => SavingsMath::centavos((string) $row->credit), 'debit' => SavingsMath::centavos((string) $row->debit)];
    }
}
