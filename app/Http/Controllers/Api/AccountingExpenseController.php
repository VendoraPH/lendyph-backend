<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\PayExpenseRequest;
use App\Http\Requests\Accounting\StoreExpenseRequest;
use App\Http\Requests\Accounting\UpdateExpenseRequest;
use App\Http\Resources\AccountingExpenseResource;
use App\Models\AccountingExpense;
use App\Services\Accounting\ExpenseRecorder;
use App\Services\BranchAccess;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * Expenses and payables.
 *
 * Every write here goes through {@see ExpenseRecorder}, which posts the journal
 * in the same transaction as the row. There is no path through this controller
 * that records a cost without also recording the entry for it.
 */
class AccountingExpenseController extends Controller
{
    public function __construct(private ExpenseRecorder $recorder) {}

    #[OA\Get(
        path: '/api/accounting/expenses',
        summary: 'List expenses and payables',
        description: 'One page of expenses, newest first. Returns the raw Laravel paginator envelope ({data, links, meta}). `per_page` is clamped at 100 and defaults to 15. Each row carries `amount_due` (amount less amount paid). `meta.totals` cover every matching expense, the `status` filter included, not the page. `meta.stats` counts expenses per status with every filter except `status`; `overdue` is by today in Manila. Amounts are integer CENTAVOS.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15, maximum: 100)),
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'unpaid | partially_paid | paid | overdue. `overdue` is derived from `due_date`, not stored.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Payee or reference, anywhere in the text. `%`, `_` and `\\` match literally.', schema: new OA\Schema(type: 'string', maxLength: 100)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated expense list',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'meta', type: 'object', properties: [
                        new OA\Property(property: 'totals', type: 'object', properties: [
                            new OA\Property(property: 'count', type: 'integer'),
                            new OA\Property(property: 'amount', type: 'integer', description: 'Centavos.'),
                            new OA\Property(property: 'amount_paid', type: 'integer', description: 'Centavos.'),
                            new OA\Property(property: 'amount_due', type: 'integer', description: 'Centavos.'),
                        ]),
                        new OA\Property(property: 'stats', type: 'object', properties: [
                            new OA\Property(property: 'unpaid', type: 'integer'),
                            new OA\Property(property: 'partially_paid', type: 'integer'),
                            new OA\Property(property: 'paid', type: 'integer'),
                            new OA\Property(property: 'overdue', type: 'integer'),
                        ]),
                    ]),
                ]),
            ),
            new OA\Response(response: 403, description: 'Missing expenses:view, or a branch_id outside the caller\'s branches'),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('expenses:view');

        $filters = request()->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            // `min:1` matters: `?per_page=0` reaches Builder::paginate() as
            // `$perPage ?: $model->getPerPage()` and silently becomes 15, while
            // a negative value reaches the database as a negative LIMIT.
            'per_page' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(AccountingExpense::STATUSES)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        // The same clamp every list endpoint in this file applies. Asking for
        // more is not an error and not a warning — it is simply fewer rows in a
        // response shaped exactly like a complete one. The screen pages on the
        // server and reads its figures from `meta.totals` and `meta.stats`.
        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        app(BranchAccess::class)->authorizeBranchFilter(request()->user(), $filters['branch_id'] ?? null);

        // Every filter but `status`, so the status counts can share it.
        $filtered = fn (): Builder => $this->visibleToCaller(AccountingExpense::query())
            ->search($filters['search'] ?? null)
            ->when(isset($filters['from']), fn ($q) => $q->where('date', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($q) => $q->where('date', '<=', $filters['to']))
            ->when(isset($filters['branch_id']), fn ($q) => $q->where('branch_id', $filters['branch_id']));

        $matching = fn (): Builder => $filtered()
            ->when(
                isset($filters['status']),
                fn ($q) => $q->withStatus((string) $filters['status']),
            );

        $expenses = $matching()
            // One query for the whole page instead of one per row. The table
            // renders `expense_account_name` on every line.
            ->with('expenseAccount:id,code,name')
            // A TOTAL order, not just `date desc`. See
            // AccountingExpense::scopeNewestFirst() — a partial order lets a
            // paged list serve one row twice and another not at all.
            ->newestFirst()
            ->paginate($perPage)
            ->withQueryString();

        return AccountingExpenseResource::collection($expenses)
            ->additional(['meta' => [
                'totals' => $this->listTotals($matching()),
                'stats' => $this->statusCounts($filtered),
            ]]);
    }

    #[OA\Post(
        path: '/api/accounting/expenses',
        summary: 'Record an expense',
        description: 'Writes the expense AND its journal in one transaction — `expense_cash` (Dr expense, Cr money account) when `payment_account_id` is given, `expense_accrual` (Dr expense, Cr accounts payable) when it is not. Refuses with 422, writing nothing, when the accounts payable role is unmapped: an expense with no journal is a cost that appears on this screen and on no statement. Amounts are INTEGER CENTAVOS.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['date', 'payee', 'expense_account_id', 'amount'],
                properties: [
                    new OA\Property(property: 'date', type: 'string', format: 'date', example: '2026-09-18'),
                    new OA\Property(property: 'payee', type: 'string', maxLength: 160, example: 'Meralco'),
                    new OA\Property(property: 'expense_account_id', type: 'integer'),
                    new OA\Property(property: 'amount', type: 'integer', description: 'Centavos. ₱15,000.50 is 1500050.', example: 1500050),
                    new OA\Property(property: 'payment_account_id', type: 'integer', nullable: true, description: 'Null accrues it as a payable.'),
                    new OA\Property(property: 'branch_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'reference', type: 'string', nullable: true, maxLength: 64),
                    new OA\Property(property: 'description', type: 'string', nullable: true, maxLength: 500),
                    new OA\Property(property: 'due_date', type: 'string', format: 'date', nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Expense recorded, journal posted'),
            new OA\Response(response: 403, description: 'Missing expenses:create'),
            new OA\Response(response: 422, description: 'Validation error, or a posting account is unmapped'),
        ],
    )]
    public function store(StoreExpenseRequest $request): JsonResponse
    {
        $expense = $this->recorder->record($request->validated(), $request->user()?->id);

        return $this->respond($expense, 201);
    }

    #[OA\Get(
        path: '/api/accounting/expenses/{id}',
        summary: 'Show an expense',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Expense details, with `amount_due` in centavos'),
            new OA\Response(response: 403, description: 'Missing expenses:view'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(AccountingExpense $expense): JsonResponse
    {
        $this->authorize('expenses:view');

        return $this->respond($expense);
    }

    #[OA\Put(
        path: '/api/accounting/expenses/{id}',
        summary: 'Update an expense',
        description: 'Descriptive fields only — `payee`, `reference`, `description`, `due_date`. `amount`, `expense_account_id`, `payment_account_id`, `date` and `branch_id` are REFUSED with 422: the journal is already posted and posted entries are immutable, so editing them here would leave the expense and the books disagreeing with nothing to join them. Reverse the journal and re-record instead.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent),
        responses: [
            new OA\Response(response: 200, description: 'Expense updated'),
            new OA\Response(response: 403, description: 'Missing expenses:update'),
            new OA\Response(response: 422, description: 'Validation error, or an attempt to edit a posted figure'),
        ],
    )]
    public function update(UpdateExpenseRequest $request, AccountingExpense $expense): JsonResponse
    {
        $expense->update($request->validated());

        return $this->respond($expense->refresh());
    }

    #[OA\Post(
        path: '/api/accounting/expenses/{id}/pay',
        summary: 'Settle an accrued expense',
        description: 'Posts `payable_payment` — Dr Accounts Payable, Cr the money account — and advances `amount_paid`. NO expense line: the cost was recognised on accrual and debiting it again would report the same spending twice. Partial payments are allowed and each one is its own journal. Refuses an expense that was paid on the spot (there is no payable to settle), one already settled, and any amount above what is outstanding.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['date', 'amount', 'account_id'],
                properties: [
                    new OA\Property(property: 'date', type: 'string', format: 'date'),
                    new OA\Property(property: 'amount', type: 'integer', description: 'Centavos.'),
                    new OA\Property(property: 'account_id', type: 'integer', description: 'The money account it is paid from.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Payment recorded, journal posted'),
            new OA\Response(response: 403, description: 'Missing expenses:pay'),
            new OA\Response(response: 422, description: 'Nothing outstanding, overpayment, or an unmapped posting account'),
        ],
    )]
    public function pay(PayExpenseRequest $request, AccountingExpense $expense): JsonResponse
    {
        $paid = $this->recorder->pay($expense, $request->validated(), $request->user()?->id);

        return $this->respond($paid);
    }

    /**
     * One expense in the `{"data": {...}}` envelope `api.get`/`api.post` unwrap,
     * with the relation the resource needs to emit `expense_account_name`.
     */
    private function respond(AccountingExpense $expense, int $status = 200): JsonResponse
    {
        $expense->load('expenseAccount:id,code,name');

        return (new AccountingExpenseResource($expense))->response()->setStatusCode($status);
    }

    /**
     * `{count, amount, amount_paid, amount_due}` over every expense the list
     * matches (its `status` filter included), not the page, in one aggregate.
     * Integer centavos; `amount_due` is `amount - amount_paid` summed in SQL,
     * the same figure as each row's `amount_due`.
     *
     * @param  Builder<AccountingExpense>  $matching
     * @return array{count: int, amount: int, amount_paid: int, amount_due: int}
     */
    private function listTotals(Builder $matching): array
    {
        $row = $matching
            ->toBase()
            ->selectRaw('COUNT(*) as row_count, COALESCE(SUM(amount), 0) as amount, COALESCE(SUM(amount_paid), 0) as amount_paid, COALESCE(SUM(amount - amount_paid), 0) as amount_due')
            ->first();

        return [
            'count' => (int) $row->row_count,
            'amount' => (int) $row->amount,
            'amount_paid' => (int) $row->amount_paid,
            'amount_due' => (int) $row->amount_due,
        ];
    }

    /**
     * Expenses per derived status, with every filter except `status`: the
     * screen's tab counts. Each count goes through
     * AccountingExpense::scopeWithStatus(), so a count always agrees with the
     * rows its tab lists (overdue by Manila's today).
     *
     * @param  Closure(): Builder<AccountingExpense>  $filtered
     * @return array<string, int> status => count, for every AccountingExpense::STATUSES
     */
    private function statusCounts(Closure $filtered): array
    {
        $counts = [];

        foreach (AccountingExpense::STATUSES as $status) {
            $counts[$status] = $filtered()->withStatus($status)->count();
        }

        return $counts;
    }

    /**
     * Rows in the caller's branches (BranchAccess). A row with no branch is
     * visible only to `branches:view_all`.
     *
     * @param  Builder<AccountingExpense>  $query
     * @return Builder<AccountingExpense>
     */
    private function visibleToCaller(Builder $query): Builder
    {
        $branchIds = app(BranchAccess::class)->for(request()->user());

        return $branchIds === null ? $query : $query->whereIn('branch_id', $branchIds);
    }
}
