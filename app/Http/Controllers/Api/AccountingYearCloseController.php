<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\CloseYearRequest;
use App\Http\Requests\Accounting\ReopenYearRequest;
use App\Models\AccountingPeriod;
use App\Models\AccountingYearClose;
use App\Models\AccountingYearCloseEntry;
use App\Models\User;
use App\Services\Accounting\Money;
use App\Services\Accounting\YearEndCloser;
use App\Services\BranchAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use OpenApi\Attributes as OA;

/**
 * The year-end close (Phase 2, round C, slice C3): the list of closes, the
 * preview, closing a year and reopening the latest one. Every figure is
 * integer centavos and comes from {@see YearEndCloser}; the screen never adds
 * anything up.
 *
 * A row's entries follow the accounting branch rule: a branch user sees only
 * their branches' entries, the entry for journals with no branch is for
 * `branches:view_all` only, and the row's `net_surplus` is the sum of the
 * entries the caller sees.
 */
class AccountingYearCloseController extends Controller
{
    private const PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    public function __construct(
        private YearEndCloser $closer,
        private BranchAccess $branches,
    ) {}

    #[OA\Get(
        path: '/api/accounting/year-closes',
        summary: 'Year-end closes, newest year first',
        description: 'The paginator envelope ({data, links, meta}), 25 rows a page (`per_page` at most 100), newest year first. Each row: `id`, `year`, `status` (`closed` or `reopened`), `net_surplus` (centavos, signed: a profit is positive), `net_surplus_account` {id, code, name}, `entries` [{branch {id, name} or null, journal {id, journal_no}, net_surplus}], `periods_closed` [{id, name}], `closed_at`, `closed_by` {id, name} or null, `reopened_at`, `reopened_by`, `reopen_reason`. Entries are filtered to the caller\'s branches (the no-branch entry with branches:view_all only) and `net_surplus` is their sum. `meta.totals.net_surplus` is the same sum over every row whose status is `closed` (a reopened close is left out).',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'One page of closes, with the totals'),
            new OA\Response(response: 403, description: 'Missing accounting:view'),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('accounting:view');

        $filters = request()->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $visible = $this->branches->for(request()->user());
        $perPage = min((int) ($filters['per_page'] ?? self::PER_PAGE), self::MAX_PER_PAGE);

        $closes = $this->withRelations(AccountingYearClose::query())
            ->orderByDesc('year')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $periodNames = $this->periodNames($closes->getCollection());
        $closes->through(fn (AccountingYearClose $close): array => $this->row($close, $visible, $periodNames));

        // Closes that stand only: a reopened close and the later close of the
        // same year would otherwise count one year's result twice.
        $totals = AccountingYearCloseEntry::query()
            ->whereIn('accounting_year_close_id', AccountingYearClose::query()
                ->where('status', AccountingYearClose::STATUS_CLOSED)
                ->select('id'))
            ->when($visible !== null, fn ($query) => $query->whereIn('branch_id', $visible))
            ->pluck('net_surplus')
            ->map(fn ($amount): int => (int) $amount)
            ->all();

        return JsonResource::collection($closes)->additional(['meta' => [
            'totals' => ['net_surplus' => Money::sum($totals)],
        ]]);
    }

    #[OA\Get(
        path: '/api/accounting/year-closes/preview',
        summary: 'What closing a year would post and close',
        description: '`{data: {year, as_of: "YYYY-12-31", net_surplus_account {id, code, name} or null, branches: [{branch {id, name} or null, lines: [{account_id, account_code, account_name, type, amount}], total_income, total_expenses, net_surplus}], total_income, total_expenses, net_surplus, periods_to_close: [{id, name}], draft_journals, can_close, blocking_reasons: [string]}}`. Centavos; a line\'s `amount` is the account\'s net for the year as it normally reads (income credit − debit, expense debit − credit). Branches with nothing to close are left out; named branches come in name order, the no-branch group last. Closing entries and their reversals are left out of the figures. Posts nothing; makes sure each month of an ended year the books cover has its period row.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'year', in: 'query', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The preview'),
            new OA\Response(response: 403, description: 'Missing accounting:close or branches:view_all'),
            new OA\Response(response: 422, description: 'A missing or invalid year'),
        ],
    )]
    public function preview(): JsonResponse
    {
        $this->authorize('accounting:close');
        $this->authorize(BranchAccess::VIEW_ALL);

        $filters = request()->validate([
            'year' => ['required', 'integer', 'between:1900,9999'],
        ]);

        return response()->json(['data' => $this->closer->preview((int) $filters['year'])]);
    }

    #[OA\Post(
        path: '/api/accounting/year-closes',
        summary: 'Close a year',
        description: 'Posts one `closing` journal per branch with income or expense in the year (plus one for journals with no branch), dated 31 December, moving each account\'s net to the `net_surplus` account, then closes every month of the year still open. Answers 201 with the row as in the list. 422 with every blocking reason on `year`; 409 when the year is already closed, another close is running, or the year\'s first journal was posted as the close started (a retry closes every month).',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['year'], properties: [
            new OA\Property(property: 'year', type: 'integer', example: 2025),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The close'),
            new OA\Response(response: 403, description: 'Missing accounting:close or branches:view_all'),
            new OA\Response(response: 409, description: 'Already closed, a close is racing this one, or the year\'s first journal raced it; retry'),
            new OA\Response(response: 422, description: 'The year cannot be closed yet (errors.year lists why)'),
        ],
    )]
    public function store(CloseYearRequest $request): JsonResponse
    {
        $close = $this->closer->close((int) $request->validated('year'), $request->user());

        return response()->json(['data' => $this->present($close, $request->user())], 201);
    }

    #[OA\Post(
        path: '/api/accounting/year-closes/{id}/reopen',
        summary: 'Reopen the latest closed year',
        description: 'Reopens the months the close closed and reverses its closing journals on 31 December, with the reason; the row stays as `reopened`. 422 (on `close`) unless the row is `closed` and its year is the latest closed year.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [
            new OA\Property(property: 'reason', type: 'string', maxLength: 500),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The reopened close'),
            new OA\Response(response: 403, description: 'Missing accounting:close or branches:view_all'),
            new OA\Response(response: 404, description: 'No such close'),
            new OA\Response(response: 409, description: 'Another close or reopen of the year was saved at the same time'),
            new OA\Response(response: 422, description: 'Not closed, not the latest closed year, or no reason'),
        ],
    )]
    public function reopen(ReopenYearRequest $request, AccountingYearClose $yearClose): JsonResponse
    {
        $close = $this->closer->reopen($yearClose, (string) $request->validated('reason'), $request->user());

        return response()->json(['data' => $this->present($close, $request->user())]);
    }

    /** @return array<string, mixed> */
    private function present(AccountingYearClose $close, User $user): array
    {
        $close = $this->withRelations(AccountingYearClose::query())->findOrFail($close->id);

        return $this->row($close, $this->branches->for($user), $this->periodNames(collect([$close])));
    }

    /**
     * @template TQuery of \Illuminate\Database\Eloquent\Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    private function withRelations($query)
    {
        return $query->with([
            'entries.branch:id,name',
            'entries.journal:id,journal_no',
            'netSurplusAccount:id,code,name',
            'closer:id,first_name,last_name',
            'reopener:id,first_name,last_name',
        ]);
    }

    /**
     * The names of every period the closes list, in one query.
     *
     * @param  Collection<int, AccountingYearClose>  $closes
     * @return array<int, string>
     */
    private function periodNames(Collection $closes): array
    {
        $ids = $closes->flatMap(fn (AccountingYearClose $close): array => $close->periods_closed ?? [])->unique()->values();

        return AccountingPeriod::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * One close in the contract shape, its entries filtered to `$visible`
     * (null: every branch, the no-branch entry included).
     *
     * @param  list<int>|null  $visible
     * @param  array<int, string>  $periodNames
     * @return array<string, mixed>
     */
    private function row(AccountingYearClose $close, ?array $visible, array $periodNames): array
    {
        $entries = $close->entries
            ->filter(fn (AccountingYearCloseEntry $entry): bool => $visible === null
                || ($entry->branch_id !== null && in_array($entry->branch_id, $visible, true)))
            ->sort(function (AccountingYearCloseEntry $a, AccountingYearCloseEntry $b): int {
                if ($a->branch === null || $b->branch === null) {
                    return ($a->branch === null) <=> ($b->branch === null);
                }

                return [$a->branch->name, $a->branch_id] <=> [$b->branch->name, $b->branch_id];
            })
            ->values();

        return [
            'id' => $close->id,
            'year' => $close->year,
            'status' => $close->status,
            'net_surplus' => Money::sum($entries->pluck('net_surplus')->all()),
            'net_surplus_account' => [
                'id' => $close->net_surplus_account_id,
                'code' => (string) $close->netSurplusAccount?->code,
                'name' => (string) $close->netSurplusAccount?->name,
            ],
            'entries' => $entries->map(fn (AccountingYearCloseEntry $entry): array => [
                'branch' => $entry->branch === null ? null : ['id' => $entry->branch->id, 'name' => $entry->branch->name],
                'journal' => [
                    'id' => (int) $entry->accounting_journal_id,
                    'journal_no' => $entry->journal?->journal_no,
                ],
                'net_surplus' => $entry->net_surplus,
            ])->all(),
            'periods_closed' => array_map(
                fn (int $id): array => ['id' => $id, 'name' => $periodNames[$id] ?? ''],
                array_map('intval', $close->periods_closed ?? []),
            ),
            'closed_at' => $close->closed_at?->toJSON(),
            'closed_by' => $this->person($close->closer),
            'reopened_at' => $close->reopened_at?->toJSON(),
            'reopened_by' => $this->person($close->reopener),
            'reopen_reason' => $close->reopen_reason,
        ];
    }

    /** @return array{id: int, name: string}|null */
    private function person(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'name' => $user->full_name];
    }
}
