<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StoreAccountingAccountRequest;
use App\Http\Requests\Accounting\UpdateAccountingAccountRequest;
use App\Http\Resources\AccountingAccountResource;
use App\Models\AccountingAccount;
use App\Services\Accounting\ChartOfAccountsSeeder;
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

        return AccountingAccountResource::collection(
            AccountingAccount::query()->inCodeOrder()->paginate($perPage)
        );
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

        return new AccountingAccountResource($account->refresh());
    }

    #[OA\Delete(
        path: '/api/accounting/accounts/{id}',
        summary: 'Delete an account',
        description: 'Refuses (422) when the account has children or when a posting role resolves to it. Both are restricted by foreign keys as well; these checks turn what would be a 500 into something a screen can render.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Account deleted'),
            new OA\Response(response: 403, description: 'Missing chart_of_accounts:delete'),
            new OA\Response(response: 422, description: 'Account has children or is referenced by a posting role'),
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

        $account->delete();

        return response()->json(['message' => 'Account deleted successfully.']);
    }
}
