<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AutoPay\ToggleAutoPayRequest;
use App\Http\Requests\Loan\AddLoanCoMakerRequest;
use App\Http\Requests\Loan\ApproveLoanRequest;
use App\Http\Requests\Loan\AssignAccountOfficerRequest;
use App\Http\Requests\Loan\ExtendLoanRequest;
use App\Http\Requests\Loan\ExtensionPreviewRequest;
use App\Http\Requests\Loan\PreviewLoanRequest;
use App\Http\Requests\Loan\RejectLoanRequest;
use App\Http\Requests\Loan\ReleaseLoanRequest;
use App\Http\Requests\Loan\ReleasePreviewRequest;
use App\Http\Requests\Loan\RestructureLoanRequest;
use App\Http\Requests\Loan\SendBackApprovedLoanRequest;
use App\Http\Requests\Loan\StoreLoanRequest;
use App\Http\Requests\Loan\SubmitLoanRequest;
use App\Http\Requests\Loan\UpdateLoanRequest;
use App\Http\Resources\AmortizationScheduleResource;
use App\Http\Resources\CoMakerResource;
use App\Http\Resources\LoanLedgerEntryResource;
use App\Http\Resources\LoanResource;
use App\Models\AmortizationSchedule;
use App\Models\CreditDecision;
use App\Models\Loan;
use App\Services\AmortizationBalanceService;
use App\Services\AutoPayService;
use App\Services\BranchAccess;
use App\Services\CollateralAttacher;
use App\Services\CollateralWriteTransaction;
use App\Services\ContactVisibility;
use App\Services\CreditScoring\PolicyFlags;
use App\Services\LikePattern;
use App\Services\LoanAdjustmentService;
use App\Services\LoanListTotals;
use App\Services\LoanReleaseFeeService;
use App\Services\LoanService;
use App\Services\PersistedScheduleFigures;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
        'next_due',
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

    /**
     * The applications GET /loans/{id}/deductions-preview quotes: the ones still
     * on their way to release. Past `approved` the deductions are the stored
     * ones, and they are not a preview of anything.
     *
     * @var array<int, string>
     */
    private const DEDUCTIONS_PREVIEW_STATUSES = ['draft', 'for_review', 'approved'];

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

`status=past_due` is the second virtual value: a **collectible** loan
(`released`, `ongoing` or `defaulted`) holding at least one unpaid amortization
schedule that is **late** — past its due date *and* past the `grace_period_days`
the loan grants. It is not a member of the `loans.status` enum and no loan row
can carry it — it is derived from the schedule — but it is a real filter, and
its row count always equals `meta.stats.past_due` under the same `branch_id`
and `borrower_id`. It overlaps `released`, `ongoing` and `defaulted` by design,
so the tab badges do not sum to the book total, and it is **not** a subset of
`active`.

Grace matters here: a loan one day past due on a product granting seven days'
grace is **not** past due on this filter, and becomes so on the eighth day.

`status=current` is the third virtual value, the Current tab: a `released` or
`ongoing` loan that is **not** past due by that same rule, so each active loan
reads under exactly one of the two. Not a member of the enum either. Its row
count always equals `meta.stats.current`. `status=active` still returns the whole
set, past due or not.

Deliberately not the same as the Due/Past Due report at
`/api/reports/due-past-due`. They agree on which loans are collectible, but that
report asks what is **owed** — `due_date <= today`, grace ignored — because its
bucket is "Due and Past Due" combined. This asks what is **late**. Expect the
report's count to be the larger of the two.

Because a comma-separated list is an OR, `status=completed,past_due` means
"completed OR past due".

A value that is neither a stored status nor one of those three virtual values
matches nothing rather than returning a 422: an empty result instead of a broken
page.

Each row's `display_status` is `past_due` for a `released` or `ongoing` loan the
past due rule catches, otherwise its `status`. A `defaulted` loan keeps reading
`defaulted`, though `status=past_due` reaches it.

`view=summary` answers rows without `amortization_schedules` or any other heavy
relation, for a list that shows many loans: `id`, `application_number`,
`loan_account_number`, `borrower` {`id`, `full_name`, `borrower_code`},
`borrower_id`, `borrower_name`, `loan_product` {`id`, `name`}, `loan_product_id`,
`loan_product_name`, `branch` {`id`, `name`}, `principal_amount`, `interest_rate`,
`interest_method`, `term`, `term_unit`, `frequency`, `maturity_date`, `status`,
`display_status`, `current_approver`, `current_status_label`, `is_restructure`,
`released_at`, `release_date`, `created_at`, `next_due_date`, `next_due_amount`
(the full row's `current_due`, also sent under that name), `amount_due_now`,
`total_outstanding`, `outstanding_principal`, `overdue_amount`, `penalty_amount`,
`total_payable` and `insurance_owed`. Each equals the same key on the full row.
Filters, sorting, paging, `meta.totals` and `meta.stats` are the same. Any other
value is a 422.

`date_from` / `date_to` are an inclusive whole-day range on **`created_at`**
(application date), so `date_to=2026-08-27` includes a loan captured at
23:59 on the 27th. A reversed range simply matches nothing.

`search` matches the application number, the loan account number, the loan
product's name, and the borrower as `GET /api/borrowers` searches them: code, any
name part, the whole name as typed ("Juan Dela Cruz"), contact number or email. The
contact number and email match only for a caller holding `borrowers:view`.

`sort` accepts only `application_number`, `borrower`, `product`, `amount`,
`term`, `status`, `created_at` and `next_due`; anything else is a 422. `next_due`
orders by each loan's earliest unpaid due date (the row's `next_due_date`), soonest
first unless `dir=desc` is sent, with loans that have none last either way. `borrower` orders by
the borrower's last name then first name (`full_name` is computed in PHP and
cannot be ordered by), `product` by loan product name, and `status` by the
order of the tabs on the loans screen — draft, for_review, approved, rejected,
released, ongoing, completed, everything else (`defaulted`, `restructured`,
`void`) last — NOT alphabetically. `dir` is `asc` or `desc` and defaults to `desc` on `created_at`,
which is the newest-first order the list has always had. Every sort carries a
final tiebreak on `id`, so paging through equal values cannot repeat or skip a
loan.

Each row carries `co_makers`, in the same shape as `GET /api/loans/{id}`
(including each link's `added_by` and `added_at`), so a borrower's loans can
be shown with their co-makers without one request per loan. Eager-loaded: the
list costs the same number of queries however many rows or co-makers it
returns.

Each row carries `current_approver`, the name of the approval step a
`for_review` loan is waiting on, so the list can read "For Approval - Manager"
without one `GET /api/loans/{id}/approval-steps` per row. It is the first
`pending` step of that endpoint's `current_steps`, so the two always agree. It
is null when the loan is not `for_review` (including an `approved` loan whose
release step is pending), when no step is pending, or when the step's name is
blank. Never an empty string. The chain is eager-loaded: one query per page,
not one per row.

`awaiting_me=1` keeps only the loans waiting on the signed-in user's role:
`for_review` loans whose current approval step (the step `current_approver`
names, the first `pending` step of the latest round) has a `role` equal to one
of the user's active role names, compared exactly. A deactivated role counts
for nothing, except `admin` and `super_admin`, which always count. A `pending`
row left in an earlier round does not count. There is no admin/super_admin
bypass: those roles see only steps that name `admin` or `super_admin`. For the
"awaiting me" badge, read `meta.total` of `?awaiting_me=1&per_page=1`.
`awaiting_me=0` or absent changes nothing.

`meta.totals` adds up every loan the filters match (every filter, not just the
page): `total_outstanding` is everything those loans still owe (the periods'
principal, interest and penalty plus the insurance premium owed, the parts of
`GET /api/loans/{id}/summary` `outstanding_balance`), `overdue_amount` is the
principal, interest and penalty unpaid on periods past their due date and the
loan's grace period (each row's `overdue_amount`, summed), and `overdue_count`
is how many of those loans have an overdue amount. One aggregate query.

Every figure covers only the loans in the caller's branches (all branches with
`branches:view_all`; none for a user with no branch). A `branch_id` outside the
caller's branches answers 403.

`meta.stats` covers every loan the caller can see, narrowed by `branch_id` and
`borrower_id` only, and never by `search`, `status`, `loan_product_id`,
`awaiting_me` or the date range. Those counts are the KPI cards and tab badges: scoping them to the
current filter would make every tab read the number of rows already on screen.
It carries one entry per status in the enum, plus `active` (the sum of the
statuses `status=active` selects), `past_due` (the row count `status=past_due`
returns) and `current` (the row count `status=current` returns).
DESC,
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Application number, loan account number, loan product name, or the borrower\'s code or name (a part or the whole name). A caller holding `borrowers:view` also matches the borrower\'s contact number and email.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'One status, a comma-separated list, or a virtual value: `active`, `past_due` or `current` (preferred over spelling statuses out).', schema: new OA\Schema(type: 'string', example: 'active')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'borrower_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'loan_product_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, description: 'Inclusive lower bound on created_at (YYYY-MM-DD).', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, description: 'Inclusive upper bound on created_at, whole day (YYYY-MM-DD).', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'awaiting_me', in: 'query', required: false, description: 'When 1, only `for_review` loans whose current approval step (the one `current_approver` names) belongs to one of the signed-in user\'s active roles, by exact role name. No admin/super_admin bypass. `meta.total` is the badge count.', schema: new OA\Schema(type: 'boolean', default: false)),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'created_at', enum: self::SORT_KEYS)),
            new OA\Parameter(name: 'dir', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'desc', enum: ['asc', 'desc'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15, maximum: 100)),
            new OA\Parameter(name: 'view', in: 'query', required: false, description: '`summary`: rows without schedules or other heavy relations. See the description.', schema: new OA\Schema(type: 'string', enum: ['summary'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated loan list'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'A `branch_id` outside the caller\'s branches'),
            new OA\Response(response: 422, description: 'Unknown sort key, bad direction, unknown view or malformed date'),
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
            'awaiting_me' => ['nullable', 'boolean'],
            'view' => ['nullable', Rule::in(['summary'])],
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
        $awaitingMe = filter_var($filters['awaiting_me'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $wantsSummary = ($filters['view'] ?? null) === 'summary';

        $viewer = request()->user();
        app(BranchAccess::class)->authorizeBranchFilter($viewer, $branchId);

        // The filters alone, shared by the page and by `meta.totals`, so the
        // totals cover exactly the loans the page is drawn from. Every query
        // below starts from the caller's visible branches (BranchAccess), so
        // the page, `meta.stats` and `meta.totals` all follow the same scope.
        $filtered = Loan::visibleTo($viewer)
            ->when(filled($search), function ($q) use ($search, $viewer) {
                $like = LikePattern::contains($search);
                $matchContactDetails = $viewer->can('borrowers:view');

                $q->where(function ($query) use ($like, $search, $matchContactDetails) {
                    $query->where('loans.application_number', 'like', $like)
                        ->orWhere('loans.loan_account_number', 'like', $like)
                        ->orWhereHas('borrower', fn ($bq) => $bq->search($search, $matchContactDetails))
                        ->orWhereHas('loanProduct', fn ($pq) => $pq->where('name', 'like', $like));
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
            ->when(filled($dateTo), fn ($q) => $q->where('loans.created_at', '<=', Carbon::parse($dateTo)->endOfDay()))
            ->when($awaitingMe, fn ($q) => $q->awaitingApprovalFromRoles(
                request()->user()->activeRoles()->pluck('name')->all(),
            ));

        $query = (clone $filtered)
            // Do NOT reorder these two lines, and do not delete the select.
            //
            // withCount() only injects its own `loans.*` when the column list
            // is still null, so putting it first and this second would leave
            // `extension_count` off the select and silently reinstate a COUNT
            // per row. Dropping the select altogether is worse: sort=borrower
            // joins `borrowers`, whose `id`, `status` and `branch_id` would
            // then hydrate OVER the loan's, and LoanResource would publish the
            // borrower's id and status on every row of the list.
            ->select('loans.*');

        if ($wantsSummary) {
            // `status` on the borrower is not for the row: amount_due_now reads
            // it to decide whether a payment credits the build-up.
            $query->with(
                'borrower:id,borrower_code,first_name,middle_name,last_name,suffix,status',
                'loanProduct:id,name',
                'branch:id,name',
                'openSchedules.postedPrincipalPayments',
                'openSchedules.principalChanges',
                'approvalSteps',
            );
        } else {
            $query
                ->with('borrower', 'loanProduct', 'branch', 'createdByUser', 'amortizationSchedules.postedPrincipalPayments', 'amortizationSchedules.principalChanges', 'coMakers', 'approvalSteps')
                // Aliased count so LoanResource's extension_count reads an
                // already-loaded value instead of firing a COUNT query per row.
                ->withCount(['adjustments as extension_count' => fn ($q) => $q->where('adjustment_type', 'extension')]);
        }

        $sort = $filters['sort'] ?? 'created_at';

        // Soonest first is what `next_due` is for. Every other key keeps the
        // newest-first default.
        $this->applySort($query, $sort, $filters['dir'] ?? ($sort === 'next_due' ? 'asc' : 'desc'));

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
        $stats = Loan::visibleTo($viewer)
            ->when(filled($branchId), fn ($q) => $q->forBranch($branchId))
            ->when(filled($borrowerId), fn ($q) => $q->where('loans.borrower_id', $borrowerId))
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        /**
         * Past due cannot come out of the GROUP BY above.
         *
         * It is not a status but a property of the loan's schedule, so it cuts
         * ACROSS `released` and `ongoing` rather than partitioning alongside
         * them: folding it into that result set would count those loans twice
         * and leave the tab badges summing to more than the book.
         *
         * One extra aggregate, unconditional, so the request stays at a fixed
         * query count no matter how many rows the page returns. It repeats the
         * two filled() scopes above deliberately — the count and the page it
         * sits over must narrow together, or a branch-filtered Past Due tab
         * would open under a whole-book badge. Same scope rules as the rest of
         * meta.stats: branch and borrower only, never `search`, `status`,
         * `loan_product_id` or the date range.
         */
        $pastDueCount = Loan::visibleTo($viewer)
            ->when(filled($branchId), fn ($q) => $q->forBranch($branchId))
            ->when(filled($borrowerId), fn ($q) => $q->where('loans.borrower_id', $borrowerId))
            ->pastDue()
            ->count();

        /**
         * The Current tab, the active loans that are not past due. Like the past
         * due count it cuts across `released` and `ongoing`, so it is its own
         * aggregate through the scope `status=current` filters on, with the same
         * two scopes.
         */
        $currentCount = Loan::visibleTo($viewer)
            ->when(filled($branchId), fn ($q) => $q->forBranch($branchId))
            ->when(filled($borrowerId), fn ($q) => $q->where('loans.borrower_id', $borrowerId))
            ->current()
            ->count();

        /**
         * Money totals over every loan the filters match, not just this page:
         * unlike `meta.stats`, they follow every filter, because they are the
         * figures for the list on screen. One aggregate query. See
         * LoanListTotals for what each figure counts.
         */
        $totals = app(LoanListTotals::class)->for($filtered);

        return ($wantsSummary ? LoanResource::summaryCollection($loans) : LoanResource::collection($loans))
            ->additional(['meta' => [
                'totals' => [
                    'total_outstanding' => $totals['total_outstanding'],
                    'overdue_amount' => $totals['overdue_amount'],
                    'overdue_count' => $totals['overdue_count'],
                ],
                'stats' => [
                    'draft' => (int) ($stats['draft'] ?? 0),
                    'for_review' => (int) ($stats['for_review'] ?? 0),
                    'approved' => (int) ($stats['approved'] ?? 0),
                    'rejected' => (int) ($stats['rejected'] ?? 0),
                    'released' => (int) ($stats['released'] ?? 0),
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
                    // The Past Due tab. NOT a status — `past_due` is not in the
                    // enum and must never be added to it — but a real filter: a
                    // collectible loan holding an unpaid schedule that is past both
                    // its due date and the loan's grace period. Counted through the
                    // same Loan::scopePastDue() that `?status=past_due` filters on,
                    // so this badge and the page that tab opens cannot disagree.
                    // It overlaps `released`, `ongoing` and `defaulted` by design,
                    // and is not a subset of `active`.
                    'past_due' => $pastDueCount,
                    // The Current tab. Not a status either: a released or ongoing
                    // loan that is not past due (Loan::scopeCurrent()), the row
                    // count `?status=current` returns.
                    'current' => $currentCount,
                ],
            ]]);
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
            // Each loan's earliest unpaid due date, the row's `next_due_date`,
            // from one probe of (loan_id, status, due_date) per loan. Rows with
            // none go last in either direction: they have nothing to chase.
            'next_due' => $query
                ->addSelect(['next_unpaid_due_date' => AmortizationSchedule::query()
                    ->selectRaw('MIN(due_date)')
                    ->whereColumn('amortization_schedules.loan_id', 'loans.id')
                    ->whereIn('amortization_schedules.status', AmortizationSchedule::UNPAID_STATUSES)])
                ->orderByRaw('next_unpaid_due_date IS NULL')
                ->orderBy('next_unpaid_due_date', $dir),
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
                    new OA\Property(property: 'co_maker_ids', type: 'array', items: new OA\Items(type: 'integer'), example: [7], description: 'Member (borrower) ids, as the co-maker picker sends them — never co-maker record ids. Each resolves to that member\'s co-maker record, created the first time they are picked. Rejected members are refused.'),
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
            new OA\Response(response: 409, description: 'Another loan application was created at the same moment (it took this one\'s application number, or the write deadlocked); nothing was created. Message: "Another loan application was created at the same moment. Submit again."'),
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
        description: '`current_approver` names the approval step a `for_review` loan is waiting on: the first `pending` step of `current_steps` in `GET /api/loans/{id}/approval-steps`. Null when the loan is not `for_review`, no step is pending, or the step name is blank. Never an empty string. Same value as the loan\'s row on `GET /api/loans`.',
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
            'createdByUser', 'accountOfficer', 'amortizationSchedules.principalChanges', 'amortizationSchedules.postedPrincipalPayments',
            'documents',
            // For current_approver.
            'approvalSteps',
            // Restructure lineage — loaded here only. index() would pay for it
            // on every row of every page to render a link almost nothing uses.
            'sourceLoan', 'restructuredInto',
            // For `collateral_summary`, the collaterals card. Here only, for
            // the same reason.
            'collaterals',
        );

        return new LoanResource($loan);
    }

    #[OA\Put(
        path: '/api/loans/{id}',
        summary: 'Update loan',
        description: 'Update loan application (only if draft or for_review). `collaterals` is optional and is the loan\'s complete collateral list. Absent: collateral is not touched. A list (`[]` included): collateral the loan holds and the list leaves out is detached, listed collateral it does not hold yet is attached at the server\'s value, its `amount` or for share capital its member\'s balance (same rules and guards as POST /api/loans/{loanId}/collaterals; a sent `snapshot_value` is ignored), and collateral it already holds keeps the value it was pledged at. Each collateral attached or detached is recorded in the audit log against the loan (`collateral_attached`, `collateral_detached`). `null` is a 422. Sending the key requires `collaterals:update` as well as `loans:update`. The loan fields and the collateral change are saved together or not at all.',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'collaterals',
                    type: 'array',
                    description: 'The complete list. Each collateral must be registered to the loan\'s borrower, listed once, and not pledged to another active loan.',
                    items: new OA\Items(
                        required: ['collateral_id'],
                        properties: [
                            new OA\Property(property: 'collateral_id', type: 'integer', example: 1),
                            new OA\Property(property: 'snapshot_value', type: 'number', deprecated: true, description: 'Ignored. The server values the pledge.'),
                        ],
                    ),
                ),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Loan updated'),
            new OA\Response(response: 403, description: 'Missing loans:update, or sent `collaterals` without collaterals:update'),
            new OA\Response(response: 409, description: 'Nothing was written; reload and try again. Another change to this loan or its collateral was saved at the same time, a new co-maker record collided on its number, or, with no `collaterals` sent, the loan stopped being editable after the request read it (checked on the locked loan row, so a loan approved mid-request is never changed).'),
            new OA\Response(response: 422, description: 'Validation error or not editable (on `status`; with `collaterals`, checked again on the locked loan row, so a loan approved while the request was in flight is refused). Collateral errors are on `collaterals.{index}.collateral_id`: not registered to this loan\'s borrower, listed twice, no longer exists, or already pledged to another active loan (the message names it)'),
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
        description: 'Delete loan application (only if draft). Its collateral is detached first, each recorded as `collateral_detached` in the audit log against the loan, as DELETE /api/loans/{loanId}/collaterals/{id} records it.',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Loan deleted'),
            new OA\Response(response: 404, description: 'Loan not found'),
            new OA\Response(response: 409, description: 'Another change to this loan\'s collateral was saved at the same time; nothing was deleted. Reload and try again'),
            new OA\Response(response: 422, description: 'Cannot delete: only draft loans can be deleted'),
        ],
    )]
    public function destroy(Loan $loan): JsonResponse
    {
        $this->authorize('loans:void');

        if ($loan->status !== 'draft') {
            return $this->onlyDraftsCanBeDeleted();
        }

        // Deleting the row would take its pledges with it through the
        // `loan_collaterals` cascade, unrecorded, so they are detached first,
        // each recorded as any detach is. A pledge write, so it takes the
        // pledge writes' lock order (CollateralAttacher): what the loan holds,
        // read before the transaction, locked in one id-ordered statement,
        // then the loan row, whose status is checked again under that lock.
        $heldBefore = CollateralAttacher::heldBy($loan);

        CollateralWriteTransaction::run(function () use ($loan, $heldBefore): void {
            $collaterals = CollateralAttacher::lock($heldBefore);
            $locked = Loan::whereKey($loan->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'draft') {
                throw new HttpResponseException($this->onlyDraftsCanBeDeleted());
            }

            // Credit decisions are an append-only record of a person's
            // decision on this application; deleting the loan would orphan
            // them (credit_decisions.loan_id is nulled on delete).
            if (CreditDecision::query()->where('loan_id', $locked->getKey())->exists()) {
                throw new HttpResponseException(response()->json([
                    'message' => 'This draft has credit decisions recorded against it, which are kept as a record, so it can\'t be deleted. Reject or void it instead.',
                ], 422));
            }

            CollateralAttacher::detachAllLocked($locked, $collaterals->keys()->all(), request()->user());

            // The draft's credit flags are closed with it, never left to
            // become the member's own when the delete nulls their loan.
            app(PolicyFlags::class)->closeForDeletedLoan((int) $locked->getKey(), Carbon::now());

            $locked->delete();
        });

        return response()->json(['message' => 'Loan deleted successfully.']);
    }

    private function onlyDraftsCanBeDeleted(): JsonResponse
    {
        return response()->json(['message' => 'Only draft loans can be deleted.'], 422);
    }

    #[OA\Patch(
        path: '/api/loans/{id}/submit',
        summary: 'Submit loan for approval',
        description: 'Needs `loans:update`. A restructure application (`is_restructure`) can also be submitted with `loans:restructure`.',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Loan submitted for approval'),
            new OA\Response(response: 403, description: 'Missing loans:update, or loans:restructure for a restructure application'),
            new OA\Response(response: 422, description: 'Invalid status transition'),
        ],
    )]
    public function submit(SubmitLoanRequest $request, Loan $loan): JsonResponse
    {
        $this->loanService->submitForReview($loan, $request->user());

        return response()->json(['message' => 'Loan submitted for approval.', 'data' => new LoanResource($loan)]);
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
            new OA\Response(response: 409, description: 'Another decision on this loan (an approve, a reject or an approval-chain step) was saved at the same time, or the write deadlocked; nothing was written and nobody was notified. Message: "Another change to this loan was saved at the same time. Reload and try again."'),
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
            new OA\Response(response: 409, description: 'Another decision on this loan (an approve, a reject or an approval-chain step) was saved at the same time, or the write deadlocked; nothing was written and nobody was notified. Message: "Another change to this loan was saved at the same time. Reload and try again."'),
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
        description: 'Release an approved loan — generates loan account number and amortization schedule. Applies the fee rules configured in Settings that match this loan\'s product and conditions, ADDING them to any deductions already itemised on the loan. Optionally withholds an insurance premium: the server computes it from `insurance_premium_percentage` (principal × percentage / 100, to the centavo, half up), the figure GET /api/loans/{id}/release-preview quotes. If the share capital the final deductions withhold is above 0, the member\'s share capital ledger is credited with it in the same transaction (one row carrying the loan), and a borrower who is not a member yet is refused (422 on `deductions`). Collisions with another write answer 409 and write nothing.',
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
                    new OA\Property(property: 'insurance_premium_amount', type: 'number', nullable: true, description: 'Optional, from older clients. Computed by the server; a sent value that differs from the server\'s by any centavo is a 422 on this field ("The premium for X% is ₱Y. Reload the release preview.").'),
                    new OA\Property(property: 'insurance_payment_type', type: 'string', nullable: true, enum: ['full', 'partial']),
                    new OA\Property(property: 'insurance_partial_amount', type: 'number', nullable: true, description: 'Required when payment_type=partial (0 allowed: nothing collected now), and no more than the server\'s premium (else 422 on this field). Must be absent, null or 0 when payment_type=full.'),
                    new OA\Property(property: 'insurance_remaining_balance', type: 'number', nullable: true, description: 'Accepted and ignored: the server computes it (0 when full; premium − partial amount when partial).'),
                    new OA\Property(property: 'fee_fingerprint', type: 'string', nullable: true, description: 'The `fee_fingerprint` returned by GET /api/loans/{id}/release-preview. Optional. When sent and the fee configuration has changed since that preview, the release is refused with 409 and nothing is written.'),
                    new OA\Property(property: 'method', type: 'string', enum: ['cash', 'gcash', 'maya', 'bank_transfer', 'online'], default: 'cash', description: 'How the net proceeds went out (a restructure: how its deductions were collected). Stored as `release_method`; the release journal settles through that method\'s account.'),
                    new OA\Property(property: 'reference_number', type: 'string', nullable: true, maxLength: 100, description: 'Required unless `method` is cash. Stored as `release_reference`.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Loan released'),
            new OA\Response(response: 409, description: 'The fee configuration changed since the preview this release quoted, or another change to this loan was saved at the same time; nothing was written. Reload and try again'),
            new OA\Response(response: 422, description: 'Invalid status transition, fees exceeding principal, a sent premium that is not the server\'s, a partial amount above the premium, insurance above the net proceeds, share capital withheld for a borrower who is not a member, an unknown `method` or a missing `reference_number`, or a restructure whose source was paid down after approval below its principal (on `principal_amount`)'),
        ],
    )]
    public function release(ReleaseLoanRequest $request, Loan $loan): JsonResponse
    {
        $loan = $this->loanService->release(
            $loan,
            $request->user(),
            $request->insurancePayload(),
            $request->feeFingerprint(),
            $request->releaseMethod(),
            $request->releaseReference(),
        );
        $loan->load('borrower', 'loanProduct', 'branch', 'coMakers',
            'approvedByUser', 'releasedByUser', 'amortizationSchedules.principalChanges', 'amortizationSchedules.postedPrincipalPayments');

        return response()->json(['message' => 'Loan released successfully.', 'data' => new LoanResource($loan)]);
    }

    #[OA\Patch(
        path: '/api/loans/{id}/send-back-approved',
        summary: 'Send an approved restructure back for approval',
        description: 'For an approved restructure that is not released yet, for example one whose source was paid down or adjusted after approval (the release then answers 422 on `principal_amount`). The loan goes back to `for_review` with its approval cleared; the release step of the current round is recorded as sent back with the reason, and a new round opens with the submit step pending and every other step waiting. The restructure is then re-submitted and approved again through its approval chain, and its figures are taken again from what the source owes on re-submit and on approval. Writes a `restructure_sent_back` audit row. The creator and the account officer are told it came back, and the submit step\'s role that it is theirs (in-app, staff only). `can_send_back_approved` on the loan says whether the caller may do this.',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason'],
                properties: [
                    new OA\Property(property: 'reason', type: 'string', maxLength: 1000, description: 'Required, trimmed: why it is going back.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Restructure sent back; `data` is the loan, now `for_review`'),
            new OA\Response(response: 403, description: 'Missing loans:release'),
            new OA\Response(response: 404, description: 'No such loan, or outside the caller\'s branches'),
            new OA\Response(response: 409, description: 'The restructure was sent back, released or voided since it was read, or another change to it or its source was saved at the same time; nothing was written and nobody was notified. Message: "Another change to this loan was saved at the same time. Reload and try again."'),
            new OA\Response(response: 422, description: 'Missing or too long `reason`, or (on `status`) the loan is not a restructure or the restructure is not approved'),
        ],
    )]
    public function sendBackApproved(SendBackApprovedLoanRequest $request, Loan $loan): JsonResponse
    {
        $this->loanService->sendBackApproved($loan, $request->user(), $request->reason());

        return response()->json(['message' => 'Restructure sent back for approval.', 'data' => new LoanResource($loan)]);
    }

    #[OA\Get(
        path: '/api/loans/{id}/release-preview',
        summary: 'Preview the deductions a release would apply',
        description: <<<'TXT'
        Read-only. Returns the itemised deductions this loan would carry after release, the resulting `total_deductions` and `net_proceeds`, and a `fee_fingerprint` over the fee rules the figures were computed from.

        The list is the loan's EXISTING deductions (the ones stated when the application was created, or the loan product's own fee columns when none were sent) plus one item per configured fee in Settings that matches this loan's product and conditions. Fee-sourced items carry an extra `fee_id`; every item keeps the `{name, amount, type, original_value}` shape the release dialog already reads.

        Runs the identical calculation the release itself runs, guard included — a fee schedule that would withhold more than the principal is refused here, in front of the cashier, rather than at the counter.

        Insurance: send the release dialog's `insurance_premium_percentage` (0–100, up to 2 places), `insurance_payment_type` (`full`, the default, or `partial`) and, for a partial payment, `insurance_partial_amount` (0 allowed), validated as the release validates them. `insurance` then quotes `premium_amount` (the principal × the percentage / 100, to the centavo, half up: the figure the release withholds), `collected` (the premium, or the partial amount), `partial_amount` (null on a full payment) and `remaining_balance`; it is null with no percentage or 0. `total_deductions_after_insurance` (`total_deductions` + collected), `net_proceeds_after_insurance` (`net_proceeds` − collected, negative when it overruns) and `exceeds_net_proceeds` (collected above `net_proceeds`, which the release refuses) are always present, equal to the totals without insurance. All figures are 2dp strings.

        Pass the returned `fee_fingerprint` back to `PATCH /api/loans/{id}/release` to be refused with a 409 if the fee configuration changed in between.

        A restructure also gets `restructure`, by the code its release posts with, writing nothing: `carried_principal` (the source's unpaid principal the new loan carries), `capitalised_interest` and `capitalised_penalty` (taken into the principal, interest first), `written_off_principal` (the source's unpaid principal not carried), `collected_now` (every deduction, insurance included, collected from the member: equals `total_deductions_after_insurance`) and `paid_out` (always 0.00). 2dp strings. A source no longer open answers 422 on `source_loan_id`, and one paid down below the principal since approval 422 on `principal_amount`. Ordinary loans get no `restructure` key.
        TXT,
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'insurance_premium_percentage', in: 'query', required: false, schema: new OA\Schema(type: 'number', minimum: 0, maximum: 100)),
            new OA\Parameter(name: 'insurance_payment_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['full', 'partial'])),
            new OA\Parameter(name: 'insurance_partial_amount', in: 'query', required: false, schema: new OA\Schema(type: 'number', minimum: 0)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Projected deductions, totals, fee fingerprint and insurance figures'),
            new OA\Response(response: 403, description: 'Missing loans:release'),
            new OA\Response(response: 422, description: 'Loan is not awaiting release, the fees would exceed the principal, malformed insurance parameters, or a partial amount above the premium (on `insurance_partial_amount`)'),
        ],
    )]
    public function releasePreview(ReleasePreviewRequest $request, Loan $loan, LoanReleaseFeeService $fees): JsonResponse
    {
        // `loans:release` rather than `loans:view`. The handoff asks for this to
        // be "authorized for the releasing role", and it is the right call: this
        // is a disbursement figure, quoted to whoever is about to count money
        // out, and the roles that can look at a loan are a much wider set than
        // the roles that can release one.
        $this->authorize('loans:release');

        // Only from the state a release can actually happen from. A preview run
        // against an already-released loan would answer with fee rules that
        // have no bearing on the money that already left the drawer — and,
        // worse, would look like a second charge about to be made.
        if (! $loan->is_releasable) {
            throw ValidationException::withMessages([
                'status' => ["Loan must be in 'approved' status to preview its release."],
            ]);
        }

        $preview = $fees->preview($loan);

        // What the insurance the dialog is filling in would collect, and what
        // that leaves, by the code the release withholds it with.
        $insurance = $this->loanService->insurancePreview(
            $loan,
            $request->insurancePayload(),
            $preview['total_deductions'],
            $preview['net_proceeds'],
        );

        // A restructure: what its release posts, collected from the member
        // rather than paid out.
        $restructure = $loan->source_loan_id === null ? [] : [
            'restructure' => $this->loanService->restructureReleasePreview($loan, $insurance['total_deductions_after_insurance']),
        ];

        return response()->json(['data' => [...$preview, ...$insurance, ...$restructure]]);
    }

    #[OA\Get(
        path: '/api/loans/{id}/deductions-preview',
        summary: 'The deductions an application will carry at release',
        description: <<<'TXT'
        Read-only. The same calculation as GET /api/loans/{id}/release-preview, guard included, for anyone who can see the loan: the application's stored deductions plus the catalog fees the release will charge, and the resulting `total_deductions` and `net_proceeds` as 2dp strings. `deductions` holds the same items as that endpoint.

        `provisional` is true until the loan is approved. Fee settings can still change the figures until release, and insurance is chosen at release, so neither this nor the release preview without its insurance parameters includes it. There is no `fee_fingerprint`: only the release dialog quotes one.
        TXT,
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Projected deductions and totals',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'object', properties: [
                        new OA\Property(property: 'deductions', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'total_deductions', type: 'string', example: '1400.00'),
                        new OA\Property(property: 'net_proceeds', type: 'string', example: '18600.00'),
                        new OA\Property(property: 'provisional', type: 'boolean'),
                    ]),
                ]),
            ),
            new OA\Response(response: 403, description: 'Missing loans:view'),
            new OA\Response(response: 404, description: 'Loan not found, or in a branch the caller cannot see'),
            new OA\Response(response: 422, description: 'Loan is not draft, for_review or approved (`status`), or the fees would exceed the principal (`fees`)'),
        ],
    )]
    public function deductionsPreview(Loan $loan, LoanReleaseFeeService $fees): JsonResponse
    {
        $this->authorize('loans:view');

        if (! in_array($loan->status, self::DEDUCTIONS_PREVIEW_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => ['Deductions can only be previewed for a loan that has not been released.'],
            ]);
        }

        return response()->json(['data' => [
            ...Arr::only($fees->preview($loan), ['deductions', 'total_deductions', 'net_proceeds']),
            'provisional' => $loan->status !== 'approved',
        ]]);
    }

    #[OA\Post(
        path: '/api/loans/{id}/co-makers',
        summary: 'Add a co-maker to a loan awaiting release',
        description: <<<'DESC'
The release dialog's "Add Co-Maker". Links a co-maker to the loan, and records who added them and when.

Send **either** `co_maker_id` — one of this borrower's existing, **active** co-maker records, as listed by `GET /api/borrowers/{id}/co-makers` — **or** a new co-maker's details, which creates the co-maker on the loan's borrower (it then also appears in that list) and links it. Sending both is a 422.

Only while the loan is awaiting release (`approved`). Any other status, a released loan included, is a 422 on `status`: once a loan is released its co-makers cannot be added to through the app.

Requires `loans:release`, the permission that releases the loan; `borrowers:create` does not grant it.

The response's co-maker carries `added_by` (user id) and `added_at`, the same two fields each entry of `GET /api/loans/{id}` `co_makers` now carries. Writes a `co_maker_added` audit entry against the loan.
DESC,
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'co_maker_id', type: 'integer', nullable: true, example: 12, description: 'An existing, active co-maker of this loan\'s borrower. When sent, none of the fields below may be.'),
                    new OA\Property(property: 'first_name', type: 'string', example: 'Maria', description: 'Required without co_maker_id'),
                    new OA\Property(property: 'middle_name', type: 'string', nullable: true),
                    new OA\Property(property: 'last_name', type: 'string', example: 'Santos', description: 'Required without co_maker_id'),
                    new OA\Property(property: 'suffix', type: 'string', nullable: true),
                    new OA\Property(property: 'address', type: 'string', nullable: true),
                    new OA\Property(property: 'contact_number', type: 'string', nullable: true, example: '09181234567'),
                    new OA\Property(property: 'occupation', type: 'string', nullable: true),
                    new OA\Property(property: 'employer', type: 'string', nullable: true),
                    new OA\Property(property: 'monthly_income', type: 'number', nullable: true, example: 20000),
                    new OA\Property(property: 'relationship_to_borrower', type: 'string', nullable: true, example: 'Spouse'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Co-maker linked to the loan',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Co-maker added to the loan.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            description: 'The co-maker, as in GET /api/loans/{id} `co_makers`. The whole record needs `borrowers:view` and the member\'s own branch; otherwise only the name, code and relationship.',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer'),
                                new OA\Property(property: 'co_maker_code', type: 'string', example: 'CMK-000012'),
                                new OA\Property(property: 'borrower_id', type: 'integer'),
                                new OA\Property(property: 'full_name', type: 'string', example: 'Maria Santos'),
                                new OA\Property(property: 'added_by', type: 'integer', nullable: true, description: 'Id of the user who linked the co-maker to the loan'),
                                new OA\Property(property: 'added_at', type: 'string', format: 'date-time'),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing loans:release'),
            new OA\Response(response: 404, description: 'Loan not found'),
            new OA\Response(response: 422, description: 'Loan is not awaiting release (`status`), co_maker_id is not this borrower\'s, is inactive or is already on the loan, both shapes sent, or a missing name'),
        ],
    )]
    public function addCoMaker(AddLoanCoMakerRequest $request, Loan $loan): JsonResponse
    {
        $coMaker = $this->loanService->addCoMakerAwaitingRelease($loan, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Co-maker added to the loan.',
            'data' => app(ContactVisibility::class)->allows($request->user(), $loan->borrower?->branch_id)
                ? new CoMakerResource($coMaker)
                : CoMakerResource::slim($coMaker),
        ], 201);
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

    #[OA\Get(
        path: '/api/loans/{id}/extension-preview',
        summary: 'Preview what extending a loan would collect and record',
        description: 'The Extend Loan dialog\'s figures, computed with the arithmetic POST /api/loans/{id}/extend records, so the dialog never quotes a figure the extension does not charge. The new due date is the first anchored date (the loan\'s anchor day, capped at month end) strictly after today, and `cycles` is how many anchored cycles the due date moves to reach it, at least 1 (more only for an overdue loan). The fresh interest is `cycles` × one month at the loan\'s rate converted from its interest_rate_frequency. A loan with a Share Capital Build-Up carries one build-up on the new period, however many cycles it moves: members are not charged share capital for the months skipped. On "pay" the collection is everything the open period owes except its principal: the insurance premium owed, the late penalty owed and the interest outstanding, allocated as a real payment would be (premium, then penalty, then interest), so the old period is settled in full, `carry_interest` is 0 and only principal moves to the new period. The carry figures are what that allocation leaves. Writes nothing; same permission and the same 422s as the extension.',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'interest_option', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['pay', 'defer'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Extension figures',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'object', properties: [
                        new OA\Property(property: 'interest_option', type: 'string', enum: ['pay', 'defer']),
                        new OA\Property(property: 'collect_now', type: 'object', description: 'Collected before extending, the same figures the extension records in its adjustment\'s new_values (amount_collected, insurance_paid, penalty_paid, interest_paid): zero on "defer". On "pay" one payment of the insurance premium still owed, the late penalty owed and the interest outstanding, taken in that order', properties: [
                            new OA\Property(property: 'amount', type: 'number', example: 3000, description: 'Total collected now: insurance_part + penalty_part + interest_part'),
                            new OA\Property(property: 'insurance_part', type: 'number', example: 0),
                            new OA\Property(property: 'penalty_part', type: 'number', example: 1200),
                            new OA\Property(property: 'interest_part', type: 'number', example: 1800),
                        ]),
                        new OA\Property(property: 'carry_principal', type: 'number', example: 60000),
                        new OA\Property(property: 'carry_interest', type: 'number', example: 0, description: 'Interest still unpaid that moves to the new period: 0 on "pay", the outstanding interest on "defer"'),
                        new OA\Property(property: 'cycles', type: 'integer', minimum: 1, example: 1, description: 'Anchored cycles the due date moves: 1, or more when the loan is overdue and one cycle would still be on or before today'),
                        new OA\Property(property: 'fresh_interest', type: 'number', example: 1800, description: '`cycles` × one cycle of interest'),
                        new OA\Property(property: 'new_interest_due', type: 'number', example: 1800),
                        new OA\Property(property: 'new_total_due', type: 'number', example: 61800),
                        new OA\Property(property: 'new_due_date', type: 'string', format: 'date', example: '2026-06-27', description: 'The first anchored date strictly after today'),
                    ]),
                ]),
            ),
            new OA\Response(response: 403, description: 'Missing loans:extend permission'),
            new OA\Response(response: 404, description: 'Loan not found'),
            new OA\Response(response: 409, description: 'Another change to this loan was being saved at the same time. Try again'),
            new OA\Response(response: 422, description: 'interest_option missing or invalid, or the loan cannot be extended: not a one-month term, not released/ongoing, no open period, more than one open period, or no principal left to carry ("Nothing is left to extend: the principal is already paid"); also when the collection could not be applied as quoted (nothing is charged)'),
        ],
    )]
    public function extensionPreview(ExtensionPreviewRequest $request, Loan $loan): JsonResponse
    {
        return response()->json([
            'data' => $this->loanAdjustmentService->extensionPreview(
                $loan,
                $request->validated('interest_option'),
                $request->user(),
                now(),
            ),
        ]);
    }

    #[OA\Post(
        path: '/api/loans/{id}/extend',
        summary: 'Extend a one-month-term loan',
        description: 'Only loans whose original term is one month (term 1 in months, paid monthly or at maturity) can be extended, as often as needed. Rolls the maturity date forward to the first anchored date (the loan\'s anchor day, capped at month end) strictly after today: one month, or for an overdue loan as many months as it takes to land after today. Accrues one month of fresh interest at the loan\'s existing rate per month moved, and records that count as `cycles` in the adjustment\'s `new_values`. A loan with a Share Capital Build-Up carries one build-up on the new period, not one per month moved. `interest_option` decides what happens to interest already outstanding: "pay" first collects, in one repayment, the insurance premium owed, the late penalty owed and the interest outstanding (in that order), so the old period is settled in full and the new period carries the principal and only the fresh interest, and the adjustment\'s `new_values` record `amount_collected`, `insurance_paid`, `penalty_paid`, `interest_paid` and the `repayment_id`; "defer" leaves it unpaid so it stacks on top (₱50 outstanding + ₱50 fresh = ₱100 due). Both the repayment and the extension happen in one transaction. Records a directly-applied LoanAdjustment row of type "extension".',
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
                    new OA\Property(property: 'interest_option', type: 'string', enum: ['pay', 'defer'], description: 'pay = collect the premium owed, the late penalty owed and the outstanding interest before extending; defer = collect nothing and carry the interest into the new period'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Loan extended; returns updated loan in GET /api/loans/{id} shape'),
            new OA\Response(response: 403, description: 'Missing loans:extend permission'),
            new OA\Response(response: 404, description: 'Loan not found'),
            new OA\Response(response: 409, description: 'Another change to this loan (another extension, an adjustment) was saved at the same time; nothing was written. Reload and try again'),
            new OA\Response(response: 422, description: 'Loan does not have a one-month term (term 1 in months, paid monthly or at maturity; `errors.term`), is not in released/ongoing status (`errors.status`), or has no open period, more than one open period, or no principal left to carry ("Nothing is left to extend: the principal is already paid") (`errors.loan`); also when the collection could not be applied as quoted, with nothing charged (`errors.loan`)'),
        ],
    )]
    public function extend(ExtendLoanRequest $request, Loan $loan): JsonResponse
    {
        $this->loanAdjustmentService->extendLoan(
            $loan,
            $request->input('remarks'),
            $request->user(),
            $request->input('interest_option'),
            $request->validated('method', 'cash'),
            $request->validated('reference_number'),
        );

        // Mirrors show()'s eager-loads, lineage included — this endpoint
        // documents itself as returning the GET /api/loans/{id} shape, and a
        // test pins the two key sets as identical.
        $loan->refresh()->load(
            'borrower', 'loanProduct', 'branch', 'coMakers',
            'approvedByUser', 'releasedByUser', 'rejectedByUser',
            'createdByUser', 'accountOfficer', 'amortizationSchedules.principalChanges', 'amortizationSchedules.postedPrincipalPayments',
            'documents', 'approvalSteps', 'sourceLoan', 'restructuredInto',
            'collaterals',
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
                    new OA\Property(property: 'co_maker_ids', type: 'array', items: new OA\Items(type: 'integer'), example: [1], description: 'Omit to inherit the source loan\'s co-makers; send [] to drop them. Otherwise, member (borrower) ids, as the co-maker picker sends them — never co-maker record ids. Each resolves to that member\'s co-maker record, created the first time they are picked. Rejected members are refused.'),
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
            new OA\Response(response: 409, description: 'Another change to the source loan\'s collateral, or to the newest loan, was saved at the same time; nothing was created. Reload and try again. Losing the application number to a create made at the same moment answers "Another loan application was created at the same moment. Submit again." instead'),
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
            new OA\Response(response: 422, description: 'Validation error or loan not in released/ongoing status'),
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

    /**
     * Reassign a loan's account officer at any status.
     *
     * Kept apart from `update()` on purpose. That endpoint re-derives the loan's
     * money, so it refuses everything outside draft/for_review. The account
     * officer is a staffing field, and it changes most often once a loan is out
     * and being collected. The change goes through the model, so `Auditable`
     * records who moved the loan and from whom.
     */
    #[OA\Patch(
        path: '/api/loans/{id}/account-officer',
        summary: 'Assign or change the account officer of a loan',
        description: 'Works at any loan status. The officer must be an active user. The change is recorded in the audit log.',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['account_officer_id'],
                properties: [
                    new OA\Property(property: 'account_officer_id', type: 'integer', example: 4),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Loan with the new account officer'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing loans:update permission'),
            new OA\Response(response: 404, description: 'Loan not found'),
            new OA\Response(response: 422, description: 'Missing, unknown or inactive user'),
        ],
    )]
    public function assignAccountOfficer(AssignAccountOfficerRequest $request, Loan $loan): LoanResource
    {
        $loan->update(['account_officer_id' => $request->integer('account_officer_id')]);
        $loan->load('borrower', 'loanProduct', 'branch', 'coMakers', 'accountOfficer');

        return new LoanResource($loan);
    }

    #[OA\Post(
        path: '/api/loans/preview',
        summary: 'The loan form\'s figures, computed by the server',
        description: <<<'TXT'
        Read-only. Every field is optional; each section is computed from what was sent.

        `collateral` is always present: `total_value` (each listed collateral valued by the server by `collateral_id` on the borrower's register: its `amount`, or for share capital its member's balance, at least 0; collateral the edited loan (`loan_id`) or the source loan (`source_loan_id`) already holds counts at the value it was pledged at, and on a restructure every pledge of the source counts, listed or not. With `loan_id` the edit's rules apply, as PUT /api/loans/{id} saves them: no `collaterals` (absent or null) counts the loan's held pledges at their stored value, `[]` counts nothing, and a list counts only its rows. A sent `snapshot_value` is ignored), `security_status` (`unsecured` with no principal or nothing pledged, `secured` when the total reaches the principal, else `partially_secured`) and `short_by` (the principal less the total, never below 0; 0 without a principal). GET /api/loans/{id} `collateral_summary` uses the same rule for a saved loan's pledges.

        `maturity_date` is where the loan would mature, as creating it would store it: null until `loan_product_id`, a `term` inside the product's term range, `frequency` and `start_date` are sent (the principal and the rate are not needed).

        `deductions` is null until `loan_product_id` and a `principal_amount` above 0 are sent. Send `deductions` as the create, edit and restructure payloads do (`{name, amount, type}`; a percentage's `amount` is the rate); absent or null means the product's own processing, service and notarial fee rates, as creating the loan falls back to, and `[]` means none. `items` are those deductions as the loan would store them (`{name, amount, type, original_value}`), `stated_total` their total; `configured_fees` are the Settings fees a release of this loan would add, matched to its product and its conditions (loan amount, term in days once `maturity_date` is known) by the release's own code, each with its `fee_id`, and `configured_total` their total; `total_deductions` is both totals, and `net_proceeds` the principal less it. When the deductions are more than the principal, `net_proceeds` is null and `error` carries the refusal saving or releasing would give (else `error` is null), in this 200 body so the rest of the preview still shows. Pesos as JSON numbers, totals added in whole centavos.

        `amortization` is null until `loan_product_id`, a `principal_amount` above 0, an `interest_rate` above 0, `term`, `frequency` and `start_date` are all sent. Its `rows` are the schedule a release of the same loan writes, built with the product's interest method, term unit and rate frequency (`interest_method` says which), each with the `share_capital_build_up` (`scb_amount`) and `total_payment` (`total_due` plus it); `totals` adds up principal, interest, build-up and payment.

        `restructure` is present only with `source_loan_id`: `{restructurable_balance, principal, shortfall, remarks_required, write_off_permission_required, collected_now, paid_out}`, by the rule POST /api/loans/{id}/restructure applies. `shortfall` is 0 when the principal reaches or exceeds the balance. The two flags state what the restructure needs, not whether the caller holds `loans:write_off`. `collected_now` (every deduction, `deductions.total_deductions`, collected from the member) and `paid_out` (always 0.00) are 2dp strings, by the code GET /api/loans/{id}/release-preview's `restructure` states them with; `collected_now` is null while `deductions` is null or carries an `error`. Insurance is chosen at release, so it is not in them here.

        The restructure edit form sends `loan_id` and `source_loan_id` together: the edited loan must be the restructure of that source (else 422 on `loan_id`), the caller needs both loans:update and loans:restructure, each id is checked for the caller's branches, and collateral counts by the edit's rules (the listed rows only, held ones at their pledged value).

        Listing collateral needs `collaterals:view`, and a share capital collateral (listed, or held by the source loan on a restructure) needs `share_capital:view`, except one the edited loan (`loan_id`) already holds. When what `collateral` counts includes a share capital pledge the caller may not see (one the edited loan holds, listed or with no list sent), `collateral` is `{total_value: null, security_status: null, short_by: null, value_unknown: true}`, never a 422, and every other section is computed as usual.

        The product's amount, rate and term ranges are not checked here; saving the loan enforces them.
        TXT,
        tags: ['Loans'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'loan_id', type: 'integer', nullable: true, description: 'The loan being edited (the edit page). Needs loans:update; the loan must be draft or for_review. With source_loan_id, it must be the restructure of that loan'),
                    new OA\Property(property: 'source_loan_id', type: 'integer', nullable: true, description: 'The loan being restructured (the restructure page, and with loan_id the restructure edit form). Needs loans:restructure'),
                    new OA\Property(property: 'borrower_id', type: 'integer', nullable: true, description: 'Required when collaterals is not empty, unless loan_id or source_loan_id is sent; if sent with either, it must be that loan\'s borrower'),
                    new OA\Property(property: 'loan_product_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'principal_amount', type: 'number', nullable: true),
                    new OA\Property(property: 'interest_rate', type: 'number', nullable: true),
                    new OA\Property(property: 'term', type: 'integer', nullable: true),
                    new OA\Property(property: 'frequency', type: 'string', nullable: true),
                    new OA\Property(property: 'start_date', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'scb_amount', type: 'number', nullable: true),
                    new OA\Property(property: 'collaterals', type: 'array', nullable: true, items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'collateral_id', type: 'integer'),
                            new OA\Property(property: 'snapshot_value', type: 'number', deprecated: true, description: 'Ignored. The server values the pledge.'),
                        ],
                    )),
                    new OA\Property(property: 'deductions', type: 'array', nullable: true, items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'name', type: 'string'),
                            new OA\Property(property: 'amount', type: 'number', description: 'The rate for a percentage, pesos for a fixed deduction'),
                            new OA\Property(property: 'type', type: 'string', enum: ['percentage', 'fixed']),
                        ],
                    )),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: '`{data: {collateral, maturity_date, deductions, amortization, restructure?}}`'),
            new OA\Response(response: 403, description: 'Missing both loans:create and loans:update; with `loan_id`, missing loans:update; with `source_loan_id`, missing loans:restructure (both ids need both)'),
            new OA\Response(response: 422, description: 'Malformed input, a collateral not on the borrower\'s register, collateral without collaterals:view, a share capital collateral without share_capital:view, a loan that can no longer be edited, an edited loan that is not the restructure of `source_loan_id`, or a source loan that cannot be restructured'),
        ],
    )]
    public function formPreview(PreviewLoanRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->loanService->formPreview($request->validated(), $request->user())]);
    }

    #[OA\Get(
        path: '/api/loans/{id}/amortization-preview',
        summary: 'Preview amortization schedule',
        description: 'Compute and return amortization schedule without persisting. `data` is the list of rows, each with its `share_capital_build_up` (the loan\'s `scb_amount`, one per row) and `total_payment` (`total_due` plus it). `totals`, a sibling of `data`, adds up `principal_due`, `interest_due`, `total_due`, `share_capital_build_up` and `total_payment` in whole centavos, built by the same code as POST /loans/preview\'s `amortization.totals`.',
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

        $schedule = $this->loanService->withShareCapitalBuildUp(
            $this->loanService->buildAmortizationPreview($loan),
            (float) $loan->scb_amount,
        );

        return response()->json(['data' => $schedule['rows'], 'totals' => $schedule['totals']]);
    }

    #[OA\Get(
        path: '/api/loans/{id}/amortization-schedule',
        summary: 'View persisted amortization schedule with payment tracking',
        description: 'Returns the saved amortization schedule with beginning balance, paid amounts, penalties, and status per installment, each with its `share_capital_build_up` (the loan\'s `scb_amount`, one per period, none on a period an extension or adjustment closed) and `total_payment` (`total_due` plus it). Includes summary totals. `summary.total_due` includes penalty (each period\'s `total_due` plus its `penalty_amount`); `summary.total_payment` excludes penalty (each period\'s `total_due` plus its build-up). `summary.total_share_capital_build_up` and `summary.total_payment` are added up in whole centavos and equal POST /loans/preview\'s `amortization.totals` for the same loan.',
        tags: ['Amortization Schedule'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Amortization schedule with payment tracking'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Loan was never released, so it has no schedule. A released loan with no periods left (a closed restructure source) gets 200 with an empty list.'),
        ],
    )]
    public function amortizationSchedule(Loan $loan): JsonResponse
    {
        $this->authorize('loans:view');

        $schedules = $loan->amortizationSchedules;

        // Only a loan that was never released has no schedule to show. A
        // released loan can legitimately have none left: closing a restructured
        // source deletes every period nothing was paid on
        // (LoanService::clearOpenSchedules), so its schedule IS empty — which
        // is an answer, not an error. The 422 made every such loan page log a
        // failed request.
        if ($schedules->isEmpty() && $loan->released_at === null) {
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

        // The same whole-centavo totals as LoanResource's
        // `amortization_schedule_totals` and POST /loans/preview.
        $figures = PersistedScheduleFigures::totals($loan, $schedules);
        $summary['total_share_capital_build_up'] = $figures['share_capital_build_up'];
        $summary['total_payment'] = $figures['total_payment'];

        return response()->json([
            'data' => AmortizationScheduleResource::forLoan($loan, $schedules),
            'summary' => $summary,
        ]);
    }

    #[OA\Get(
        path: '/api/loans/{id}/amortization-balances',
        summary: 'What is still owed on each amortization period',
        description: 'Per period, the principal, interest and penalty still owed (due minus what the payment allocation recorded against that period, floored at zero) and their total, plus whether the period is late past its grace period. The totals equal the outstanding, paid and overdue figures of GET /api/loans/{id}/summary. Read-only: nothing is allocated here.',
        tags: ['Amortization Schedule'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Per-period balances and their totals'),
            new OA\Response(response: 403, description: 'Missing loans:view permission'),
            new OA\Response(response: 404, description: 'Loan not found'),
            new OA\Response(response: 422, description: 'Loan was never released, so it has no schedule. A released loan with no periods left (a closed restructure source) gets 200 with no periods.'),
        ],
    )]
    public function amortizationBalances(Loan $loan, AmortizationBalanceService $balances): JsonResponse
    {
        $this->authorize('loans:view');

        $data = $balances->forLoan($loan);

        // Same rule as amortizationSchedule(): only a loan that was never
        // released has no schedule to answer with.
        if ($data['periods'] === [] && $loan->released_at === null) {
            return response()->json([
                'message' => 'No amortization schedule found. Loan may not have been released yet.',
            ], 422);
        }

        return response()->json(['data' => $data]);
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
