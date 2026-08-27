<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AutoPay\ToggleAutoPayRequest;
use App\Http\Requests\Loan\ApproveLoanRequest;
use App\Http\Requests\Loan\ExtendLoanRequest;
use App\Http\Requests\Loan\RejectLoanRequest;
use App\Http\Requests\Loan\ReleaseLoanRequest;
use App\Http\Requests\Loan\RestructureLoanRequest;
use App\Http\Requests\Loan\StoreLoanRequest;
use App\Http\Requests\Loan\UpdateLoanRequest;
use App\Http\Resources\AmortizationScheduleResource;
use App\Http\Resources\LoanLedgerEntryResource;
use App\Http\Resources\LoanResource;
use App\Models\Loan;
use App\Services\AutoPayService;
use App\Services\LoanAdjustmentService;
use App\Services\LoanService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class LoanController extends Controller
{
    /**
     * The only values `?sort=` accepts.
     *
     * A whitelist, not a mapping of anything the caller sends: this value ends
     * up inside an ORDER BY clause. Anything not on this list is a 422 from the
     * validator and never reaches the database. See applySort() for what each
     * key resolves to — three of them are not columns.
     *
     * @var array<int, string>
     */
    private const SORT_KEYS = [
        'application_number',
        'borrower',
        'product',
        'amount',
        'term',
        'status',
        'created_at',
    ];

    /**
     * Status order used by `?sort=status`, deliberately not alphabetical.
     *
     * It is the order of the tabs on the loans screen — the sequence the user
     * is already looking at — which is what the list sorted by while it was
     * doing this client-side.
     *
     * `ongoing` occupies the slot the Current tab occupies, because that tab
     * now points at `ongoing`; it used to say `current`, which is not a member
     * of the `loans.status` enum and so never matched a row. Ranking `ongoing`
     * explicitly is not cosmetic: left out, it falls into the unranked bucket
     * below and every live, paying loan sorts BENEATH the completed and
     * rejected ones.
     *
     * Statuses with no tab (`defaulted`, `restructured`, `void`) are absent on
     * purpose and sort after everything listed here; see statusOrderSql().
     *
     * @var array<int, string>
     */
    private const STATUS_SORT_ORDER = [
        'draft',
        'for_review',
        'approved',
        'rejected',
        'released',
        'ongoing',
        'completed',
    ];

    public function __construct(
        private LoanService $loanService,
        private LoanAdjustmentService $loanAdjustmentService,
        private AutoPayService $autoPayService,
    ) {}

    #[OA\Get(
        path: '/api/loans',
        summary: 'List loans',
        description: <<<'DESC'
Get a paginated list of loans with search, filters and sorting.

`status` takes a single status (`released`) **or a comma-separated list**
(`released,ongoing`), resolved as a `whereIn`. The list form is what backs the
loans screen's Active tab, which is more than one status at once.
`status=active` is shorthand for that whole set and is what clients should
send — the set is defined once in `Loan::ACTIVE_STATUSES`, it has already
changed once, and a list pinned in a client cannot follow it. It always agrees
with `meta.stats.active`.

A value that is not a stored status matches nothing rather than returning a
422, so a client still sending the retired `current` or `past_due` gets an
empty result instead of a broken page. Neither is a member of the
`loans.status` enum and neither is reported in `meta.stats`: the Current tab
reads `ongoing`, and past due is a schedule-derived concept (an `ongoing` loan
with an overdue amortization schedule), not a status.

`date_from` / `date_to` are an inclusive whole-day range on **`created_at`**
(application date), so `date_to=2026-08-27` includes a loan captured at
23:59 on the 27th. A reversed range simply matches nothing.

`sort` accepts only `application_number`, `borrower`, `product`, `amount`,
`term`, `status` and `created_at`; anything else is a 422. `borrower` orders by
the borrower's last name then first name (`full_name` is computed in PHP and
cannot be ordered by), `product` by loan product name, and `status` by the
order of the tabs on the loans screen — draft, for_review, approved, rejected,
released, ongoing, completed, everything else (`defaulted`, `restructured`,
`void`) last — NOT alphabetically. `dir` is `asc` or `desc` and defaults to `desc` on `created_at`,
which is the newest-first order the list has always had. Every sort carries a
final tiebreak on `id`, so paging through equal values cannot repeat or skip a
loan.

`meta.stats` is always organisation-wide — narrowed by `branch_id` and
`borrower_id` only, and never by `search`, `status`, `loan_product_id` or the
date range. Those counts are the KPI cards and tab badges: scoping them to the
current filter would make every tab read the number of rows already on screen.
It carries one entry per status in the enum, plus `active`, the sum of the
statuses `status=active` selects.
DESC,
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Application number, loan account number, borrower name or borrower code.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'One status, a comma-separated list, or `active` (preferred).', schema: new OA\Schema(type: 'string', example: 'active')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'borrower_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'loan_product_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, description: 'Inclusive lower bound on created_at (YYYY-MM-DD).', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, description: 'Inclusive upper bound on created_at, whole day (YYYY-MM-DD).', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'created_at', enum: self::SORT_KEYS)),
            new OA\Parameter(name: 'dir', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'desc', enum: ['asc', 'desc'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated loan list'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Unknown sort key, bad direction or malformed date'),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('loans:view');

        $filters = request()->validate([
            'search' => ['nullable', 'string'],
            // Not constrained to the enum on purpose — see Loan::scopeForStatus().
            'status' => ['nullable', 'string'],
            // `min:1` is not cosmetic: 0 is a valid integer that no row can
            // carry, and it used to reach a `when()` that treats it as absent
            // — see the filled() note below.
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'borrower_id' => ['nullable', 'integer', 'min:1'],
            'loan_product_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'sort' => ['nullable', Rule::in(self::SORT_KEYS)],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        /**
         * Pulled out as locals so every filter below can be gated on
         * filled() — PRESENCE — rather than on truthiness.
         *
         * `Builder::when()` skips its callback for any falsy condition, and
         * `0` and `'0'` are falsy. Gating on the value itself therefore
         * dropped `?borrower_id=0` on the floor and answered with the entire
         * loan book joined to borrower PII, plus org-wide `meta.stats`, for a
         * caller who had asked about one borrower. `/borrowers/0` on the
         * frontend does exactly that, via Number(params.id). The same hole
         * swallowed `search=0` and `status=0`, which are ordinary values a user
         * can type.
         */
        $search = $filters['search'] ?? null;
        $status = $filters['status'] ?? null;
        $branchId = $filters['branch_id'] ?? null;
        $borrowerId = $filters['borrower_id'] ?? null;
        $loanProductId = $filters['loan_product_id'] ?? null;
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        $query = Loan::query()
            // Do NOT reorder these two lines, and do not delete the select.
            //
            // withCount() only injects its own `loans.*` when the column list
            // is still null, so putting it first and this second would leave
            // `extension_count` off the select and silently reinstate a COUNT
            // per row. Dropping the select altogether is worse: sort=borrower
            // joins `borrowers`, whose `id`, `status` and `branch_id` would
            // then hydrate OVER the loan's, and LoanResource would publish the
            // borrower's id and status on every row of the list.
            ->select('loans.*')
            ->with('borrower', 'loanProduct', 'branch', 'createdByUser', 'amortizationSchedules')
            // Aliased count so LoanResource's extension_count reads an
            // already-loaded value instead of firing a COUNT query per row.
            ->withCount(['adjustments as extension_count' => fn ($q) => $q->where('adjustment_type', 'extension')])
            ->when(filled($search), function ($q) use ($search) {
                $q->where(function ($query) use ($search) {
                    $query->where('loans.application_number', 'like', "%{$search}%")
                        ->orWhere('loans.loan_account_number', 'like', "%{$search}%")
                        ->orWhereHas('borrower', function ($bq) use ($search) {
                            $bq->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('borrower_code', 'like', "%{$search}%");
                        });
                });
            })
            ->when(filled($status), fn ($q) => $q->forStatus($status))
            ->when(filled($branchId), fn ($q) => $q->forBranch($branchId))
            ->when(filled($borrowerId), fn ($q) => $q->where('loans.borrower_id', $borrowerId))
            ->when(filled($loanProductId), fn ($q) => $q->where('loans.loan_product_id', $loanProductId))
            // Inclusive whole-day range on the application date. Expressed as a
            // range rather than with whereDate(), which wraps the column in a
            // function and forfeits any index on it; endOfDay() is what makes
            // `date_to` cover the loans captured during that day rather than
            // only one created exactly at midnight.
            ->when(filled($dateFrom), fn ($q) => $q->where('loans.created_at', '>=', Carbon::parse($dateFrom)->startOfDay()))
            ->when(filled($dateTo), fn ($q) => $q->where('loans.created_at', '<=', Carbon::parse($dateTo)->endOfDay()));

        $this->applySort($query, $filters['sort'] ?? 'created_at', $filters['dir'] ?? 'desc');

        $loans = $query->paginate(min(max((int) ($filters['per_page'] ?? 15), 1), 100));

        /**
         * Status counts so the frontend can render its tabs and KPI cards
         * without a second request. Intentionally global — branch- and
         * borrower-scoped only, never narrowed by `search`, `status`,
         * `loan_product_id` or the date range, exactly as
         * BorrowerController::index() does it and for the same reason. These
         * are KPI figures: scoping them to the active filter would make each
         * tab report the size of the page already on screen.
         *
         * The two scopes it DOES honour are gated on filled() for the same
         * reason the page is, and the two must agree: fixing one alone would
         * sit a borrower-filtered page underneath whole-book counts.
         */
        $stats = Loan::when(filled($branchId), fn ($q) => $q->forBranch($branchId))
            ->when(filled($borrowerId), fn ($q) => $q->where('loans.borrower_id', $borrowerId))
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return LoanResource::collection($loans)
            ->additional(['meta' => ['stats' => [
                'draft' => (int) ($stats['draft'] ?? 0),
                'for_review' => (int) ($stats['for_review'] ?? 0),
                'approved' => (int) ($stats['approved'] ?? 0),
                'rejected' => (int) ($stats['rejected'] ?? 0),
                'released' => (int) ($stats['released'] ?? 0),
                // `current` and `past_due` were reported here and have been
                // removed. Neither is a member of the `loans.status` enum, so
                // both were structurally always 0, and a key that can only ever
                // be 0 invites the wrong repair — adding the enum member —
                // rather than the right one. The Current tab reads `ongoing`
                // below. Past due is not a status: it is an `ongoing` loan
                // holding an overdue amortization schedule, so it wants a
                // schedule-derived filter, filed as a follow-up. Do not put
                // either key back without the concept behind it. See
                // Loan::ACTIVE_STATUSES.
                'ongoing' => (int) ($stats['ongoing'] ?? 0),
                'completed' => (int) ($stats['completed'] ?? 0),
                'defaulted' => (int) ($stats['defaulted'] ?? 0),
                // Loans closed because their balance moved to a restructure.
                // Omitting this made them vanish from the frontend's tab counts.
                'restructured' => (int) ($stats['restructured'] ?? 0),
                'void' => (int) ($stats['void'] ?? 0),
                // The Active Loans KPI card: everything out the door and not
                // yet closed. Summed from the same constant `status=active`
                // filters on, so the card and the tab it opens cannot disagree
                // — including when that set changes, as it just did.
                'active' => array_sum(array_map(
                    fn (string $status) => (int) ($stats[$status] ?? 0),
                    Loan::ACTIVE_STATUSES,
                )),
            ]]]);
    }

    /**
     * Order the loans list by one already-whitelisted sort key.
     *
     * `$sort` has been checked against self::SORT_KEYS by index() before it
     * gets here, so nothing a caller typed is ever concatenated into SQL.
     */
    private function applySort(Builder $query, string $sort, string $dir): void
    {
        // Re-derived rather than trusted, because statusOrderSql() puts it in
        // raw SQL: this stays correct even if a future caller skips validation.
        $dir = strtolower($dir) === 'asc' ? 'asc' : 'desc';

        match ($sort) {
            'application_number' => $query->orderBy('loans.application_number', $dir),
            'amount' => $query->orderBy('loans.principal_amount', $dir),
            'term' => $query->orderBy('loans.term', $dir),
            'created_at' => $query->orderBy('loans.created_at', $dir),
            // Last name then first name, not the `full_name` the table shows:
            // that accessor is assembled in PHP and there is no column to order
            // by. A LEFT join rather than a correlated subquery per row — it is
            // a to-one relation on a primary key, so it can neither duplicate
            // nor drop a loan, and `borrowers.last_name` is indexed.
            'borrower' => $query
                ->leftJoin('borrowers', 'borrowers.id', '=', 'loans.borrower_id')
                ->orderBy('borrowers.last_name', $dir)
                ->orderBy('borrowers.first_name', $dir),
            'product' => $query
                ->leftJoin('loan_products', 'loan_products.id', '=', 'loans.loan_product_id')
                ->orderBy('loan_products.name', $dir),
            'status' => $query->orderByRaw($this->statusOrderSql($dir), self::STATUS_SORT_ORDER),
        };

        // Deterministic tiebreak on every sort. Without it MySQL may order rows
        // that tie differently for each page, which silently repeats one loan
        // and hides another as the user pages through — and ties are routine
        // here: a status sort has at most eight distinct values, and two loans
        // captured in the same second share a created_at.
        $query->orderBy('loans.id', $dir);
    }

    /**
     * ORDER BY expression placing statuses in the loans screen's tab order,
     * with anything that has no tab last.
     *
     * Not alphabetical, deliberately. This reproduces the ordering the list
     * already had while it sorted client-side, where the sequence was the tab
     * row the user is looking at; sorting A-Z instead would quietly reshuffle
     * every list in the product. The status values are bound as parameters
     * rather than written into the string — only the rank literals and the
     * direction, both derived here, are interpolated.
     */
    private function statusOrderSql(string $dir): string
    {
        $cases = implode(' ', array_map(
            fn (int $rank) => "when ? then {$rank}",
            array_keys(self::STATUS_SORT_ORDER),
        ));

        $unranked = count(self::STATUS_SORT_ORDER);

        return "case `loans`.`status` {$cases} else {$unranked} end {$dir}";
    }

    #[OA\Post(
        path: '/api/loans',
        summary: 'Create loan application',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['borrower_id', 'loan_product_id', 'principal_amount', 'start_date'],
                properties: [
                    new OA\Property(property: 'borrower_id', type: 'integer', example: 1),
                    new OA\Property(property: 'co_maker_ids', type: 'array', items: new OA\Items(type: 'integer'), example: [1]),
                    new OA\Property(property: 'loan_product_id', type: 'integer', example: 1),
                    new OA\Property(property: 'principal_amount', type: 'number', example: 50000),
                    new OA\Property(property: 'purpose', type: 'string', example: 'Business expansion'),
                    new OA\Property(property: 'interest_rate', type: 'number', example: 3.0),
                    new OA\Property(property: 'start_date', type: 'string', format: 'date', example: '2026-04-01'),
                    new OA\Property(property: 'scb_amount', type: 'number', example: 250, description: 'Share capital build-up per payment (0 = disabled)'),
                    new OA\Property(property: 'policy_exception', type: 'boolean', example: false),
                    new OA\Property(property: 'policy_exception_details', type: 'string', nullable: true, description: 'Required when policy_exception is true'),
                    new OA\Property(
                        property: 'deductions',
                        type: 'array',
                        items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'name', type: 'string', example: 'Processing Fee'),
                                new OA\Property(property: 'amount', type: 'number', example: 2),
                                new OA\Property(property: 'type', type: 'string', enum: ['fixed', 'percentage'], example: 'percentage'),
                            ],
                        ),
                    ),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Loan application created'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(StoreLoanRequest $request): JsonResponse
    {
        $loan = $this->loanService->createLoan($request->validated(), $request->user());
        $loan->load('borrower', 'loanProduct', 'branch', 'coMakers', 'createdByUser');

        return (new LoanResource($loan))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/loans/{id}',
        summary: 'Show loan',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Loan details'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(Loan $loan): LoanResource
    {
        $this->authorize('loans:view');

        $loan->load(
            'borrower', 'loanProduct', 'branch', 'coMakers',
            'approvedByUser', 'releasedByUser', 'rejectedByUser',
            'createdByUser', 'accountOfficer', 'amortizationSchedules',
            'documents',
            // Restructure lineage — loaded here only. index() would pay for it
            // on every row of every page to render a link almost nothing uses.
            'sourceLoan', 'restructuredInto',
        );

        return new LoanResource($loan);
    }

    #[OA\Put(
        path: '/api/loans/{id}',
        summary: 'Update loan',
        description: 'Update loan application (only if draft or for_review)',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent),
        responses: [
            new OA\Response(response: 200, description: 'Loan updated'),
            new OA\Response(response: 422, description: 'Validation error or not editable'),
        ],
    )]
    public function update(UpdateLoanRequest $request, Loan $loan): LoanResource
    {
        $loan = $this->loanService->updateLoan($loan, $request->validated(), $request->user());
        $loan->load('borrower', 'loanProduct', 'branch', 'coMakers');

        return new LoanResource($loan);
    }

    #[OA\Delete(
        path: '/api/loans/{id}',
        summary: 'Delete loan',
        description: 'Delete loan application (only if draft)',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Loan deleted'),
            new OA\Response(response: 422, description: 'Cannot delete'),
        ],
    )]
    public function destroy(Loan $loan): JsonResponse
    {
        $this->authorize('loans:void');

        if ($loan->status !== 'draft') {
            return response()->json(['message' => 'Only draft loans can be deleted.'], 422);
        }

        $loan->delete();

        return response()->json(['message' => 'Loan deleted successfully.']);
    }

    #[OA\Patch(
        path: '/api/loans/{id}/submit',
        summary: 'Submit loan for review',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Loan submitted for review'),
            new OA\Response(response: 422, description: 'Invalid status transition'),
        ],
    )]
    public function submit(Loan $loan): JsonResponse
    {
        $this->authorize('loans:update');

        $this->loanService->submitForReview($loan);

        return response()->json(['message' => 'Loan submitted for review.', 'data' => new LoanResource($loan)]);
    }

    #[OA\Patch(
        path: '/api/loans/{id}/approve',
        summary: 'Approve loan',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'approval_remarks', type: 'string'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Loan approved'),
            new OA\Response(response: 422, description: 'Invalid status transition'),
        ],
    )]
    public function approve(ApproveLoanRequest $request, Loan $loan): JsonResponse
    {
        $this->authorize('loans:approve');

        $this->loanService->approve($loan, $request->user(), $request->approval_remarks);
        $loan->load('approvedByUser');

        return response()->json(['message' => 'Loan approved.', 'data' => new LoanResource($loan)]);
    }

    #[OA\Patch(
        path: '/api/loans/{id}/reject',
        summary: 'Reject loan',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'approval_remarks', type: 'string'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Loan rejected'),
            new OA\Response(response: 422, description: 'Invalid status transition'),
        ],
    )]
    public function reject(RejectLoanRequest $request, Loan $loan): JsonResponse
    {
        $this->authorize('loans:reject');

        $this->loanService->reject($loan, $request->user(), $request->approval_remarks);

        return response()->json(['message' => 'Loan rejected.', 'data' => new LoanResource($loan)]);
    }

    #[OA\Patch(
        path: '/api/loans/{id}/release',
        summary: 'Release loan',
        description: 'Release an approved loan — generates loan account number and amortization schedule. Optionally records insurance premium fields collected at release.',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'insurance_premium_percentage', type: 'number', nullable: true, minimum: 0, maximum: 100, description: 'When 0 or omitted, insurance block is ignored.'),
                    new OA\Property(property: 'insurance_premium_amount', type: 'number', nullable: true, description: 'principal_amount × percentage / 100, rounded 2dp.'),
                    new OA\Property(property: 'insurance_payment_type', type: 'string', nullable: true, enum: ['full', 'partial']),
                    new OA\Property(property: 'insurance_partial_amount', type: 'number', nullable: true, description: 'Required (>0) when payment_type=partial. Must be null or 0 when payment_type=full.'),
                    new OA\Property(property: 'insurance_remaining_balance', type: 'number', nullable: true, description: '0 when full; premium_amount − partial_amount when partial.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Loan released'),
            new OA\Response(response: 422, description: 'Invalid status transition or insurance validation error'),
        ],
    )]
    public function release(ReleaseLoanRequest $request, Loan $loan): JsonResponse
    {
        $loan = $this->loanService->release($loan, $request->user(), $request->insurancePayload());
        $loan->load('borrower', 'loanProduct', 'branch', 'coMakers',
            'approvedByUser', 'releasedByUser', 'amortizationSchedules');

        return response()->json(['message' => 'Loan released successfully.', 'data' => new LoanResource($loan)]);
    }

    #[OA\Patch(
        path: '/api/loans/{id}/void',
        summary: 'Void loan',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Loan voided'),
            new OA\Response(response: 422, description: 'Cannot void'),
        ],
    )]
    public function void(Loan $loan): JsonResponse
    {
        $this->authorize('loans:void');

        $this->loanService->voidLoan($loan);

        return response()->json(['message' => 'Loan voided.', 'data' => new LoanResource($loan)]);
    }

    #[OA\Post(
        path: '/api/loans/{id}/extend',
        summary: 'Extend an upon-maturity loan by one cycle',
        description: 'Rolls the maturity date forward by one frequency cycle and accrues one cycle of fresh interest at the loan\'s existing rate. `interest_option` decides what happens to interest already outstanding: "pay" collects it as a repayment first, so the new period carries only the fresh interest; "defer" leaves it unpaid so it stacks on top (₱50 outstanding + ₱50 fresh = ₱100 due). Both the repayment and the extension happen in one transaction. Records a directly-applied LoanAdjustment row of type "extension".',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                required: ['interest_option'],
                properties: [
                    new OA\Property(property: 'remarks', type: 'string', nullable: true, maxLength: 1000),
                    new OA\Property(property: 'interest_option', type: 'string', enum: ['pay', 'defer'], description: 'pay = collect the outstanding interest before extending; defer = carry it into the new period'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Loan extended; returns updated loan in GET /api/loans/{id} shape'),
            new OA\Response(response: 403, description: 'Missing loans:extend permission'),
            new OA\Response(response: 404, description: 'Loan not found'),
            new OA\Response(response: 422, description: 'Loan is not upon-maturity (neither frequency nor interest_method is upon_maturity), not in released/ongoing status, or has no open period'),
        ],
    )]
    public function extend(ExtendLoanRequest $request, Loan $loan): JsonResponse
    {
        $this->loanAdjustmentService->extendLoan(
            $loan,
            $request->input('remarks'),
            $request->user(),
            $request->input('interest_option'),
        );

        // Mirrors show()'s eager-loads, lineage included — this endpoint
        // documents itself as returning the GET /api/loans/{id} shape, and a
        // test pins the two key sets as identical.
        $loan->refresh()->load(
            'borrower', 'loanProduct', 'branch', 'coMakers',
            'approvedByUser', 'releasedByUser', 'rejectedByUser',
            'createdByUser', 'accountOfficer', 'amortizationSchedules',
            'documents', 'sourceLoan', 'restructuredInto',
        );

        return response()->json([
            'message' => 'Loan extended.',
            'data' => new LoanResource($loan),
        ]);
    }

    #[OA\Post(
        path: '/api/loans/{id}/restructure',
        summary: 'Restructure a loan into a new loan',
        description: 'Creates a NEW loan derived from the source loan\'s outstanding balance (unpaid principal + interest + penalty + insurance). The new loan starts as `draft` and goes through the normal submit → approve → release flow; the source loan is untouched and stays fully collectible until that new loan is RELEASED, at which point the source is closed as `restructured`. A rejected or voided restructure leaves the source exactly as it was. `principal_amount` may not exceed the outstanding balance; anything less is a shortfall and requires `remarks`, and is recorded as `write_off_amount` on the source at closure. `interest_method` is never accepted here — it is snapshotted from the loan product, as with POST /loans.',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Source loan id', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['borrower_id', 'loan_product_id', 'principal_amount', 'start_date'],
                properties: [
                    new OA\Property(property: 'borrower_id', type: 'integer', example: 1, description: 'Must match the source loan\'s borrower'),
                    new OA\Property(property: 'co_maker_ids', type: 'array', items: new OA\Items(type: 'integer'), example: [1], description: 'Omit to inherit the source loan\'s co-makers; send [] to drop them'),
                    new OA\Property(property: 'loan_product_id', type: 'integer', example: 1),
                    new OA\Property(property: 'principal_amount', type: 'number', example: 45000, description: 'May not exceed the source loan\'s outstanding balance'),
                    new OA\Property(property: 'start_date', type: 'string', format: 'date', example: '2026-08-01'),
                    new OA\Property(property: 'purpose', type: 'string', nullable: true),
                    new OA\Property(property: 'interest_rate', type: 'number', nullable: true, example: 3.0),
                    new OA\Property(property: 'term', type: 'integer', nullable: true, example: 6),
                    new OA\Property(property: 'frequency', type: 'string', nullable: true, enum: ['daily', 'weekly', 'bi_weekly', 'semi_monthly', 'monthly', 'upon_maturity']),
                    new OA\Property(property: 'account_officer_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'scb_amount', type: 'number', nullable: true, example: 250),
                    new OA\Property(property: 'policy_exception', type: 'boolean', nullable: true, example: false),
                    new OA\Property(property: 'policy_exception_details', type: 'string', nullable: true),
                    new OA\Property(property: 'remarks', type: 'string', nullable: true, maxLength: 1000, description: 'Required when principal_amount is below the outstanding balance'),
                    new OA\Property(
                        property: 'deductions',
                        type: 'array',
                        items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'name', type: 'string', example: 'Processing Fee'),
                                new OA\Property(property: 'amount', type: 'number', example: 2),
                                new OA\Property(property: 'type', type: 'string', enum: ['fixed', 'percentage'], example: 'percentage'),
                            ],
                        ),
                    ),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'New draft loan created, with source_loan_id set'),
            new OA\Response(response: 403, description: 'Missing loans:restructure permission'),
            new OA\Response(response: 404, description: 'Loan not found'),
            new OA\Response(response: 422, description: 'Source loan is not released/ongoing, borrower mismatch, a restructure is already in progress, nothing outstanding, principal exceeds the outstanding balance, or a shortfall was sent without remarks'),
        ],
    )]
    public function restructure(RestructureLoanRequest $request, Loan $loan): JsonResponse
    {
        $newLoan = $this->loanService->restructure($loan, $request->validated(), $request->user());
        $newLoan->load('borrower', 'loanProduct', 'branch', 'coMakers', 'createdByUser', 'sourceLoan');

        return (new LoanResource($newLoan))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Patch(
        path: '/api/loans/{id}/auto-pay',
        summary: 'Enable or disable auto-pay on a loan',
        description: 'When enabled, the loan is included in subsequent /auto-pay/preview and /auto-pay/process runs. cbs_reference is required when enabling and is cleared when disabling.',
        tags: ['Auto-Pay'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['enabled'],
                properties: [
                    new OA\Property(property: 'enabled', type: 'boolean', example: true),
                    new OA\Property(property: 'cbs_reference', type: 'string', maxLength: 100, nullable: true, example: 'CBS-2026-00123', description: 'Required when enabled=true'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Auto-pay toggled'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing auto_pay:toggle permission'),
            new OA\Response(response: 404, description: 'Loan not found'),
            new OA\Response(response: 422, description: 'Validation error or loan not in released/ongoing/past_due status'),
        ],
    )]
    public function toggleAutoPay(ToggleAutoPayRequest $request, Loan $loan): JsonResponse
    {
        $loan = $this->autoPayService->toggle(
            $loan,
            (bool) $request->input('enabled'),
            $request->input('cbs_reference'),
            $request->user(),
        );

        return response()->json([
            'data' => [
                'loan_id' => $loan->id,
                'auto_pay_enabled' => (bool) $loan->auto_pay,
                'cbs_reference' => $loan->cbs_reference,
                'enabled_at' => $loan->auto_pay_enabled_at?->toIso8601String(),
                'enabled_by_user_id' => $loan->auto_pay_enabled_by,
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/loans/{id}/amortization-preview',
        summary: 'Preview amortization schedule',
        description: 'Compute and return amortization schedule without persisting',
        tags: ['Amortization Schedule'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Amortization schedule preview'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function amortizationPreview(Loan $loan): JsonResponse
    {
        $this->authorize('loans:view');

        $schedule = $this->loanService->buildAmortizationPreview($loan);

        return response()->json(['data' => $schedule]);
    }

    #[OA\Get(
        path: '/api/loans/{id}/amortization-schedule',
        summary: 'View persisted amortization schedule with payment tracking',
        description: 'Returns the saved amortization schedule with beginning balance, paid amounts, penalties, and status per installment. Includes summary totals.',
        tags: ['Amortization Schedule'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Amortization schedule with payment tracking'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Loan has no schedule yet'),
        ],
    )]
    public function amortizationSchedule(Loan $loan): JsonResponse
    {
        $this->authorize('loans:view');

        $schedules = $loan->amortizationSchedules;

        if ($schedules->isEmpty()) {
            return response()->json([
                'message' => 'No amortization schedule found. Loan may not have been released yet.',
            ], 422);
        }

        $summary = [
            'total_principal' => round($schedules->sum(fn ($s) => (float) $s->principal_due), 2),
            'total_interest' => round($schedules->sum(fn ($s) => (float) $s->interest_due), 2),
            'total_penalty' => round($schedules->sum(fn ($s) => (float) $s->penalty_amount), 2),
            'total_due' => round($schedules->sum(fn ($s) => (float) $s->total_due + (float) $s->penalty_amount), 2),
            'total_principal_paid' => round($schedules->sum(fn ($s) => (float) $s->principal_paid), 2),
            'total_interest_paid' => round($schedules->sum(fn ($s) => (float) $s->interest_paid), 2),
            'total_penalty_paid' => round($schedules->sum(fn ($s) => (float) $s->penalty_paid), 2),
            'total_paid' => round($schedules->sum(fn ($s) => (float) $s->principal_paid + (float) $s->interest_paid + (float) $s->penalty_paid), 2),
            'periods_total' => $schedules->count(),
            'periods_paid' => $schedules->where('status', 'paid')->count(),
            'periods_partial' => $schedules->where('status', 'partial')->count(),
            'periods_overdue' => $schedules->where('status', 'overdue')->count(),
            'periods_pending' => $schedules->where('status', 'pending')->count(),
        ];

        return response()->json([
            'data' => AmortizationScheduleResource::collection($schedules),
            'summary' => $summary,
        ]);
    }

    #[OA\Get(
        path: '/api/loans/{id}/ledger-entries',
        summary: 'Loan ledger entries',
        description: 'Debits and credits recorded against the loan, oldest first. Extending a loan writes a debit for the interest it accrues, plus a credit when the outstanding interest was collected first. Kept out of the loan resource because entries accumulate per extension.',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Ledger entries'),
            new OA\Response(response: 403, description: 'Missing loans:view permission'),
            new OA\Response(response: 404, description: 'Loan not found'),
        ],
    )]
    public function ledgerEntries(Loan $loan): JsonResponse
    {
        $this->authorize('loans:view');

        $entries = $loan->ledgerEntries()
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => LoanLedgerEntryResource::collection($entries),
        ]);
    }
}
