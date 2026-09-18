<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StoreAccountingAccountRequest;
use App\Http\Requests\Accounting\UpdateAccountingAccountRequest;
use App\Http\Resources\AccountingAccountResource;
use App\Models\AccountingAccount;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\TrialBalanceBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * The chart of accounts.
 *
 * `normal_balance` is never read from the request on any of these endpoints. It
 * is derived from `type` + `is_contra` — see App\Models\AccountingAccount's
 * saving hook — because an account whose stored normal balance disagreed with
 * its type would invert its own sign on the trial balance and the balance sheet
 * at the same time.
 */
class AccountingAccountController extends Controller
{
    public function __construct(private TrialBalanceBuilder $trialBalance) {}

    #[OA\Get(
        path: '/api/accounting/accounts',
        summary: 'List chart of accounts',
        description: 'One page of the chart, in code order — which is statement order, because codes are fixed-width numeric strings. Returns the raw Laravel paginator envelope ({data, links, meta}); clients drain it by following `meta.last_page`.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated account list'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing chart_of_accounts:view'),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('chart_of_accounts:view');

        $filters = request()->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            // `min:1` matters: `?per_page=0` reaches Builder::paginate() as
            // `$perPage ?: $model->getPerPage()` and silently becomes 15, while
            // a negative value reaches the database as a negative LIMIT.
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        $accounts = AccountingAccount::query()
            // One extra query for the whole page instead of one per row. The
            // alternative is an `exists` per account inside the resource, which
            // is 60 queries on a default chart and invisible until production.
            ->withCount('journalLines')
            ->inCodeOrder()
            ->paginate($perPage);

        $this->attachBalances($accounts->getCollection());

        return AccountingAccountResource::collection($accounts);
    }

    #[OA\Post(
        path: '/api/accounting/accounts',
        summary: 'Create an account',
        description: '`normal_balance` is derived from `type` and `is_contra` and is ignored if sent. The code must agree with the type (1xxx asset, 2xxx liability, 3xxx equity, 4xxx income, 5xxx expense), a parent must be a group of the same type, and a group cannot carry `cash_kind`.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code', 'name', 'type'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', maxLength: 16, example: '1180'),
                    new OA\Property(property: 'name', type: 'string', maxLength: 160, example: 'Advances to Officers'),
                    new OA\Property(property: 'type', type: 'string', enum: ['asset', 'liability', 'equity', 'income', 'expense']),
                    new OA\Property(property: 'is_contra', type: 'boolean', example: false),
                    new OA\Property(property: 'is_group', type: 'boolean', example: false),
                    new OA\Property(property: 'is_active', type: 'boolean', example: true),
                    new OA\Property(property: 'parent_id', type: 'integer', nullable: true, example: 1),
                    new OA\Property(property: 'cash_kind', type: 'string', nullable: true, enum: ['cash', 'bank', 'gcash', 'maya', 'wallet']),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Account created'),
            new OA\Response(response: 403, description: 'Missing chart_of_accounts:create'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(StoreAccountingAccountRequest $request): JsonResponse
    {
        $account = AccountingAccount::create(array_merge(
            ['is_contra' => false, 'is_group' => false, 'is_active' => true],
            $request->validated(),
            ['created_by' => $request->user()?->id],
        ));

        $account->loadCount('journalLines');

        return (new AccountingAccountResource($account))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Post(
        path: '/api/accounting/accounts/seed',
        summary: 'Seed the default chart of accounts',
        description: 'One-time. Creates the default chart and its posting defaults, and returns the whole chart in code order as a flat `{data: Account[]}` — NOT a paginator. Refuses with 409 if any account already exists, because merging would resurrect accounts an administrator removed and re-point roles they changed.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 201, description: 'The seeded chart'),
            new OA\Response(response: 403, description: 'Missing chart_of_accounts:create'),
            new OA\Response(response: 409, description: 'A chart of accounts already exists'),
        ],
    )]
    public function seed(ChartOfAccountsSeeder $seeder): JsonResponse
    {
        $this->authorize('chart_of_accounts:create');

        $accounts = $seeder->seed(request()->user()?->id);

        // Every count is zero on a chart that was created a moment ago. Loaded
        // anyway so the field is PRESENT and says so, rather than absent and
        // leaving the client to guess.
        $accounts->loadCount('journalLines');

        return AccountingAccountResource::collection($accounts)
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/accounting/accounts/{id}',
        summary: 'Show an account',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Account details'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(AccountingAccount $account): AccountingAccountResource
    {
        $this->authorize('chart_of_accounts:view');

        $account->loadCount('journalLines');
        $this->attachBalances(new Collection([$account]));

        return new AccountingAccountResource($account);
    }

    #[OA\Put(
        path: '/api/accounting/accounts/{id}',
        summary: 'Update an account',
        description: 'Partial. Any field left out keeps its stored value, and the cross-field rules are checked against what the account will BE after the save. `normal_balance` is re-derived whenever `type` or `is_contra` changes.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent),
        responses: [
            new OA\Response(response: 200, description: 'Account updated'),
            new OA\Response(response: 403, description: 'Missing chart_of_accounts:update'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function update(UpdateAccountingAccountRequest $request, AccountingAccount $account): AccountingAccountResource
    {
        $account->update($request->validated());

        $account->refresh()->loadCount('journalLines');

        return new AccountingAccountResource($account);
    }

    #[OA\Delete(
        path: '/api/accounting/accounts/{id}',
        summary: 'Delete an account',
        description: 'Refuses (422) when the account has children, when a posting role resolves to it, or when any journal line — draft or posted — references it. All three are restricted by foreign keys as well; these checks turn what would be a 500 into something a screen can render. An account with history can only be deactivated.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Account deleted'),
            new OA\Response(response: 403, description: 'Missing chart_of_accounts:delete'),
            new OA\Response(response: 422, description: 'Account has children, is referenced by a posting role, or has journal lines'),
        ],
    )]
    public function destroy(AccountingAccount $account): JsonResponse
    {
        $this->authorize('chart_of_accounts:delete');

        $children = $account->children()->count();

        if ($children > 0) {
            throw ValidationException::withMessages([
                'account' => "{$account->code} {$account->name} has {$children} account(s) beneath it. Move or remove them first.",
            ]);
        }

        $roles = $account->mappings()->pluck('role')->all();

        if ($roles !== []) {
            $list = implode(', ', $roles);

            throw ValidationException::withMessages([
                'account' => "{$account->code} {$account->name} is the account for {$list}. Point those roles somewhere else first.",
            ]);
        }

        $lines = $account->journalLines()->count();

        if ($lines > 0) {
            // The third restricting foreign key, and the one that matters most.
            // Deleting an account with history would destroy ONE HALF of
            // entries that still exist: the books would stop balancing and the
            // missing side would be unrecoverable, because a journal line is
            // the only record that the other side ever had a counterpart.
            //
            // Draft lines count, because the foreign key counts them — a check
            // that ignored drafts would answer 200 here and then 500 on the
            // DELETE. Deactivating is the remedy: it stops the account taking
            // new history while keeping what it has.
            throw ValidationException::withMessages([
                'account' => "{$account->code} {$account->name} has {$lines} journal line(s) against it and cannot be deleted. Deactivate it instead — an account with history keeps its history.",
            ]);
        }

        $account->delete();

        return response()->json(['message' => 'Account deleted successfully.']);
    }

    /**
     * Hangs each account's signed balance on it, for the resource to emit.
     *
     * From {@see TrialBalanceBuilder}, not from an aggregate of its own, and
     * that is the whole point: the Cash & Bank screen sums these into one
     * headline figure that sits a click away from the balance sheet. Two
     * aggregates would eventually disagree over a reversal or a group account,
     * and both figures would be plausible.
     *
     * Balances are ALL-TIME rather than as of a date — a money account's
     * balance is what it holds, not what it held at some cut-off — and cover
     * every branch, because the chart is organisation-wide.
     *
     * Accounts with no movement are absent from the aggregate and are set to 0
     * here, not left null: "never posted to" and "posted to and netted out" are
     * the same balance to a reader, and an absent field would be rendered as
     * "not asked for".
     *
     * @param  Collection<int, AccountingAccount>  $accounts
     */
    private function attachBalances(Collection $accounts): void
    {
        if ($accounts->isEmpty()) {
            return;
        }

        $balances = $this->trialBalance->signedBalances(
            null,
            null,
            $accounts->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
        );

        foreach ($accounts as $account) {
            // A heading has no balance of its own — what a screen shows against
            // one is the total of its subtree, computed from the rows beneath
            // it. Emitting 0 would be read as "this heading holds nothing".
            $account->balance = $account->is_group ? null : ($balances[$account->id] ?? 0);
        }
    }
}
