<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Traits\ScopesBranchFilter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\SeedChartRequest;
use App\Http\Requests\Accounting\StoreAccountingAccountRequest;
use App\Http\Requests\Accounting\UpdateAccountingAccountRequest;
use App\Http\Resources\AccountingAccountResource;
use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use App\Models\AccountingJournal;
use App\Models\AccountingOpeningBalance;
use App\Models\AccountingOpeningBalanceLine;
use App\Services\Accounting\AccountRules;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\OpeningBalancePoster;
use App\Services\Accounting\TrialBalanceBuilder;
use App\Services\LoanWriteTransaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
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
    use ScopesBranchFilter;

    /** MySQL's "Cannot delete or update a parent row": a foreign key still points here. */
    private const ROW_IS_REFERENCED = 1451;

    public function __construct(
        private TrialBalanceBuilder $trialBalance,
        private OpeningBalancePoster $openingBalances,
    ) {}

    #[OA\Get(
        path: '/api/accounting/accounts',
        summary: 'List chart of accounts',
        description: 'One page of the chart, in code order — which is statement order, because codes are fixed-width numeric strings. Returns the raw Laravel paginator envelope ({data, links, meta}); clients drain it by following `meta.last_page`. `balance` covers the caller\'s branches only (every branch, unbranched journals included, for branches:view_all).',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15, maximum: 100)),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, description: 'Balances of one of the caller\'s branches (another answers 403). Without it, every branch the caller holds.', schema: new OA\Schema(type: 'integer')),
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
            'branch_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        $accounts = AccountingAccount::query()
            // One extra query for the whole page instead of one per row. The
            // alternative is an `exists` per account inside the resource, which
            // is 60 queries on a default chart and invisible until production.
            ->withCount('journalLines')
            ->inCodeOrder()
            ->paginate($perPage);

        $this->attachBalances($accounts->getCollection(), $filters['branch_id'] ?? null);

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

    #[OA\Get(
        path: '/api/accounting/cash-accounts',
        summary: 'The money accounts, with their balances',
        description: "Cash on hand, bank accounts, GCash and Maya — every account carrying a `cash_kind` — in code order, with `balance` in integer CENTAVOS. Returns the raw Laravel paginator envelope ({data, links, meta}). `meta.totals` covers every money account, not the page: `balance` (the headline total) and `by_kind` (cash, bank, gcash, maya and wallet), in centavos and in the same branch scope as each row's balance. Balances come from the SAME TrialBalanceBuilder aggregate the trial balance and the chart of accounts read, so this screen cannot drift from them. INACTIVE money accounts are included — see the method. `balance` covers the caller's branches only (every branch, unbranched journals included, for branches:view_all).",
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15, maximum: 100)),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, description: 'Balances of one of the caller\'s branches (another answers 403). Without it, every branch the caller holds.', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated money accounts',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'meta', type: 'object', properties: [
                        new OA\Property(property: 'totals', type: 'object', properties: [
                            new OA\Property(property: 'balance', type: 'integer', description: 'Centavos, over every money account.'),
                            new OA\Property(property: 'by_kind', type: 'object', properties: [
                                new OA\Property(property: 'cash', type: 'integer'),
                                new OA\Property(property: 'bank', type: 'integer'),
                                new OA\Property(property: 'gcash', type: 'integer'),
                                new OA\Property(property: 'maya', type: 'integer'),
                                new OA\Property(property: 'wallet', type: 'integer'),
                            ]),
                        ]),
                    ]),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing cash_accounts:view'),
        ],
    )]
    public function cashAccounts(): AnonymousResourceCollection
    {
        // `cash_accounts:view`, not `chart_of_accounts:view`. The two are
        // genuinely different reads: a branch manager is granted the Cash &
        // Bank screen without being given the chart to edit, and the permission
        // vocabulary has carried this pair since the accounting permissions
        // migration specifically so this endpoint could use it.
        $this->authorize('cash_accounts:view');

        $filters = request()->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            // `min:1` matters for the same reason it does on index(): a
            // `per_page` of 0 reaches Builder::paginate() as
            // `$perPage ?: $model->getPerPage()` and silently becomes 15.
            'per_page' => ['nullable', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        $accounts = AccountingAccount::query()
            ->withCount('journalLines')
            // The money accounts are exactly the ones carrying a `cash_kind`,
            // which is what the dedicated index on that column exists for.
            // Selecting by code range or by name instead would break the moment
            // an organisation renamed "GCash" or added a second bank account.
            ->whereNotNull('cash_kind')
            // Headings are excluded because their balance is the sum of their
            // subtree: a group with a `cash_kind` would double-count every
            // account beneath it in the screen's total. attachBalances() gives
            // a group a null balance anyway, which the screen would render as
            // ₱0.00 — a card claiming the co-op holds nothing.
            ->where('is_group', false)
            // NO `is_active` filter, and that is a deliberate call rather than
            // an oversight.
            //
            // The screen's headline is "Total across all money accounts". A
            // deactivated GCash wallet that still holds ₱3,000 is money the
            // co-op has, and it is on the trial balance whether or not the
            // account accepts new entries — so excluding it would make two
            // screens in this module disagree about the cash position with
            // nothing to say which was right, which is the exact failure the
            // `signedBalances()` reuse below exists to prevent.
            //
            // The cost is that a retired account can be offered as a transfer
            // destination once that endpoint exists. That is the better of the
            // two failures: it is LOUD and recoverable — the transfer is
            // refused, because AccountRules::isPostable() requires `is_active`
            // — whereas a silently short total is a wrong number on screen,
            // formatted, with nothing about it that looks partial. `is_active`
            // rides along on every row, so the picker can filter on it.
            ->inCodeOrder()
            ->paginate($perPage);

        $this->attachBalances($accounts->getCollection(), $filters['branch_id'] ?? null);

        /*
         * `meta.totals` over EVERY money account, not the page: the same
         * accounts as the list (non-group, inactive included), from the same
         * signedBalances() aggregate and in the same branch scope as each
         * row's balance, so the headline equals the sum of the rows across
         * all pages and the trial balance's money accounts.
         */
        $kinds = AccountingAccount::query()
            ->whereNotNull('cash_kind')
            ->where('is_group', false)
            ->pluck('cash_kind', 'id');

        $balances = $this->trialBalance->signedBalances(
            null,
            $this->branchScope($filters['branch_id'] ?? null),
            $kinds->keys()->map(static fn ($id): int => (int) $id)->all(),
        );

        $byKind = array_fill_keys(AccountRules::CASH_KINDS, 0);

        foreach ($kinds as $id => $kind) {
            $byKind[$kind] += $balances[(int) $id] ?? 0;
        }

        return AccountingAccountResource::collection($accounts)
            ->additional(['meta' => ['totals' => [
                'balance' => array_sum($byKind),
                'by_kind' => $byKind,
            ]]]);
    }

    #[OA\Post(
        path: '/api/accounting/accounts/seed',
        summary: 'Seed the chart of accounts from a template',
        description: 'One-time. Creates the chart of the chosen template and its posting defaults, and returns the whole chart in code order as a flat `{data: Account[]}` — NOT a paginator. `lending_company` (the default) is the standard chart; `cooperative` adds 2050 Savings Deposits, 2060 Time Deposits, 2070 Interest on Share Capital Payable, 2080 Patronage Refund Payable, the 3070 Statutory Funds heading with 3071–3074 under it, and 3080 Undivided Net Surplus, where `net_surplus` posts. Refuses with 409 if any account already exists, because merging would resurrect accounts an administrator removed and re-point roles they changed.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'template', type: 'string', enum: ['lending_company', 'cooperative'], nullable: true, description: 'Absent or null: lending_company.'),
            ]),
        ),
        responses: [
            new OA\Response(response: 201, description: 'The seeded chart'),
            new OA\Response(response: 403, description: 'Missing chart_of_accounts:create'),
            new OA\Response(response: 409, description: 'A chart of accounts already exists'),
            new OA\Response(response: 422, description: 'An unknown template'),
        ],
    )]
    public function seed(SeedChartRequest $request, ChartOfAccountsSeeder $seeder): JsonResponse
    {
        $accounts = $seeder->seed($request->user()?->id, $request->template());

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
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, description: 'Balances of one of the caller\'s branches (another answers 403). Without it, every branch the caller holds.', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Account details'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(AccountingAccount $account): AccountingAccountResource
    {
        $this->authorize('chart_of_accounts:view');

        $filters = request()->validate([
            'branch_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $account->loadCount('journalLines');
        $this->attachBalances(new Collection([$account]), $filters['branch_id'] ?? null);

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
        description: 'Refuses (422) when the account has children, when a posting role resolves to it, when any journal line — draft or posted — references it, or when it is on the opening balances draft (told to deactivate it once the draft can no longer change). All four are restricted by foreign keys as well; these checks turn what would be a 500 into something a screen can render, and any other foreign key that still holds the account (an expense, a reconciliation, a year-end close) answers the same 422. The checks and the delete run in one transaction; a colliding change answers 409. An account with history can only be deactivated.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Account deleted'),
            new OA\Response(response: 403, description: 'Missing chart_of_accounts:delete'),
            new OA\Response(response: 409, description: 'Another change to the chart collided with this one'),
            new OA\Response(response: 422, description: 'Account has children, is referenced by a posting role, has journal lines, is on the opening balances draft, or is still in use elsewhere'),
        ],
    )]
    public function destroy(AccountingAccount $account): JsonResponse
    {
        $this->authorize('chart_of_accounts:delete');

        $label = "{$account->code} {$account->name}";

        try {
            LoanWriteTransaction::run(
                fn () => $this->deleteUnused($account, $label),
                conflictMessage: 'Someone else changed the chart of accounts at the same time. Reload and try again.',
            );
        } catch (QueryException $e) {
            // A foreign key the checks don't list (an expense, a
            // reconciliation, a year-end close) still holds the account. The
            // same 422 the checks give, never a 500.
            if (($e->errorInfo[1] ?? null) !== self::ROW_IS_REFERENCED) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'account' => "{$label} is still in use elsewhere in the books and cannot be deleted. Deactivate it instead.",
            ]);
        }

        return response()->json(['message' => 'Account deleted successfully.']);
    }

    /**
     * The checks and the delete, in one transaction, in the opening balance
     * lock order (OpeningBalancePoster): the allowance and 3050 rows, then
     * the draft, then the account row, then the draft's lines under a
     * locking read, so a draft save that adds this account either commits
     * first and is seen, or waits. A deadlock or lock wait timeout that still
     * happens answers 409 through LoanWriteTransaction::run(), never 500.
     */
    private function deleteUnused(AccountingAccount $account, string $label): void
    {
        $this->openingBalances->lockInOrder([$account->id], requireOpeningEquity: false);
        $draft = AccountingOpeningBalance::query()->lockForUpdate()->first();

        // The account row itself, before the reference checks. Their shared
        // reads lock gaps, so without this a first reference to the account
        // (a journal line, a role mapping, a sub-account) could deadlock
        // against the DELETE; with it, that reference waits its turn.
        AccountingAccount::query()->whereKey($account->id)->lockForUpdate()->first();
        $onTheDraft = AccountingOpeningBalanceLine::query()
            ->where('accounting_account_id', $account->id)
            ->sharedLock()
            ->exists();

        $children = $account->children()->sharedLock()->count();

        if ($children > 0) {
            throw ValidationException::withMessages([
                'account' => "{$label} has {$children} ".Str::plural('account', $children).' beneath it. Move or remove '.($children === 1 ? 'it' : 'them').' first.',
            ]);
        }

        $roles = $account->mappings()->sharedLock()->pluck('role')->all();

        if ($roles !== []) {
            $list = AccountingAccountMapping::labelList($roles);

            throw ValidationException::withMessages([
                'account' => "{$label} is the account for {$list}. Point those roles somewhere else first.",
            ]);
        }

        $lines = $account->journalLines()->sharedLock()->count();

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
                'account' => "{$label} has {$lines} ".Str::plural('journal line', $lines).' against it and cannot be deleted. Deactivate it instead — an account with history keeps its history.',
            ]);
        }

        // The opening balances draft's lines restrict the account too. Once
        // the draft is finalised, or the CLI has posted the opening balance,
        // the draft can't change, so removing the line there is no remedy.
        if ($onTheDraft) {
            $settled = $draft?->isFinalised() === true
                || AccountingJournal::query()->where('source', OpeningBalancePoster::SOURCE)->sharedLock()->exists();

            throw ValidationException::withMessages([
                'account' => $settled
                    ? "{$label} is on the opening balance draft, which can no longer change. Deactivate it instead."
                    : "{$label} is on the opening balance draft. Remove it there first.",
            ]);
        }

        $account->delete();
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
     * balance is what it holds, not what it held at some cut-off. The chart
     * itself is organisation-wide, but the balances follow the caller's
     * branches (decision B2): a caller without `branches:view_all` sees what
     * their branches' journals posted, and journals with no branch count only
     * for `branches:view_all`. A `branch_id` filter outside the caller's
     * branches answers 403 (ScopesBranchFilter).
     *
     * Accounts with no movement are absent from the aggregate and are set to 0
     * here, not left null: "never posted to" and "posted to and netted out" are
     * the same balance to a reader, and an absent field would be rendered as
     * "not asked for".
     *
     * @param  Collection<int, AccountingAccount>  $accounts
     * @param  mixed  $branchId  the request's `branch_id` filter, if any
     */
    private function attachBalances(Collection $accounts, mixed $branchId): void
    {
        $branchScope = $this->branchScope($branchId);

        if ($accounts->isEmpty()) {
            return;
        }

        $balances = $this->trialBalance->signedBalances(
            null,
            $branchScope,
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
