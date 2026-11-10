<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Savings\Transaction\ListSavingsTransactionsRequest;
use App\Http\Requests\Savings\Transaction\ReverseSavingsTransactionRequest;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Services\BranchAccess;
use App\Services\LikePattern;
use App\Services\Savings\Api\AccountScope;
use App\Services\Savings\Api\Presenter;
use App\Services\Savings\Api\Reverser;
use App\Services\Savings\IdempotentWrite;
use App\Services\Savings\SavingsMath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * The savings register, one transaction (the receipt), and reversal requests
 * (design §2, slice S2). Another branch's transaction answers 404.
 */
class SavingsTransactionController extends Controller
{
    public function __construct(
        private readonly AccountScope $scope,
        private readonly Presenter $presenter,
    ) {}

    #[OA\Get(
        path: '/api/savings/transactions',
        summary: 'The savings register',
        description: 'Every account\'s transactions in the caller\'s branches, newest first. `meta.totals` (`debit`, `credit`) covers the whole filter.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Reference, external reference, account number or member name', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: SavingsTransaction::TYPES)),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'One page of transactions'),
            new OA\Response(response: 403, description: 'Missing savings:view, or a branch outside the caller\'s'),
            new OA\Response(response: 422, description: 'A bad filter'),
        ],
    )]
    public function index(ListSavingsTransactionsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $user = $request->user();
        app(BranchAccess::class)->authorizeBranchFilter($user, $filters['branch_id'] ?? null);
        $search = trim((string) ($filters['search'] ?? ''));

        $matching = fn (): Builder => $this->scope->transactions($user)
            ->when(isset($filters['type']), fn ($q) => $q->where('type', $filters['type']))
            ->when(isset($filters['date_from']), fn ($q) => $q->where('date', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn ($q) => $q->where('date', '<=', $filters['date_to']))
            ->when(isset($filters['branch_id']), fn ($q) => $q->whereIn('account_id', SavingsAccount::query()->where('branch_id', $filters['branch_id'])->select('id')))
            ->when($search !== '', function (Builder $q) use ($search): void {
                $like = LikePattern::contains($search);
                $q->where(fn (Builder $w) => $w
                    ->where('reference', 'like', $like)
                    ->orWhere('external_reference', 'like', $like)
                    ->orWhereHas('account', fn (Builder $a) => $a
                        ->where('account_number', 'like', $like)
                        ->orWhereHas('borrower', fn (Builder $b) => $b
                            ->where('borrower_code', 'like', $like)
                            ->orWhereRaw("CONCAT_WS(' ', first_name, last_name) LIKE ?", [$like]))));
            });

        $page = $matching()->orderByDesc('date')->orderByDesc('id')->paginate(Presenter::perPage($filters['per_page'] ?? null));
        $sums = $matching()->toBase()->selectRaw('COALESCE(SUM(credit), 0) AS credit, COALESCE(SUM(debit), 0) AS debit')->first();

        return Presenter::page($page, $this->presenter->transactions(collect($page->items())), [
            'totals' => [
                'debit' => SavingsMath::pesos(SavingsMath::centavos((string) $sums->debit)),
                'credit' => SavingsMath::pesos(SavingsMath::centavos((string) $sums->credit)),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/savings/transactions/{transaction}',
        summary: 'A savings transaction (the receipt)',
        description: 'With `reversed_by_transaction_id` once reversed and `pending_reversal_approval_id` while a reversal waits for approval.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'transaction', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The transaction'),
            new OA\Response(response: 403, description: 'Missing savings:view'),
            new OA\Response(response: 404, description: 'No such transaction in the caller\'s branches'),
        ],
    )]
    public function show(Request $request, int $transaction): JsonResponse
    {
        $this->authorize('savings:view');

        return response()->json(['success' => true, 'data' => $this->presenter->transaction($this->scope->transaction($request->user(), $transaction))]);
    }

    #[OA\Post(
        path: '/api/savings/transactions/{transaction}/reverse',
        summary: 'Ask to reverse a transaction',
        description: 'Always 202 with `{approval}`: a reversal posts only once a checker other than the maker approves it, as a linked `reversal` row (the original never changes) with its journal reversed. Both legs of a transfer are reversed together. 422 for interest, tax, closing, time deposit and reversal rows; 409 when already reversed or a reversal is waiting. Needs an `Idempotency-Key` header.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'transaction', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [
            new OA\Property(property: 'reason', type: 'string'),
        ])),
        responses: [
            new OA\Response(response: 202, description: '`{approval}`'),
            new OA\Response(response: 403, description: 'Missing savings:transact'),
            new OA\Response(response: 404, description: 'No such transaction in the caller\'s branches'),
            new OA\Response(response: 409, description: 'Already reversed, or a reversal is waiting'),
            new OA\Response(response: 422, description: 'This kind of row can\'t be reversed, or the money is no longer there'),
        ],
    )]
    public function reverse(ReverseSavingsTransactionRequest $request, Reverser $reverser, IdempotentWrite $idempotent, int $transaction): JsonResponse
    {
        /** @var SavingsTransaction $target */
        $target = $request->record();
        $reason = $request->validated('reason');
        $user = $request->user();

        return $idempotent->run($request, fn (): JsonResponse => response()->json([
            'success' => true,
            'data' => ['approval' => $this->presenter->approval($reverser->request($target, $reason, $user))],
        ], 202));
    }
}
