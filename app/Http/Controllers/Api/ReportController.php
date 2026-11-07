<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Traits\CsvExportTrait;
use App\Http\Controllers\Controller;
use App\Http\Resources\BorrowerReleasedResource;
use App\Http\Resources\IncomeByLoanResource;
use App\Http\Resources\LoanResource;
use App\Http\Resources\RepaymentResource;
use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Loan;
use App\Services\BranchAccess;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    use CsvExportTrait;

    public function __construct(private ReportService $reportService) {}

    /**
     * The widest reporting period any report will accept, in years.
     *
     * The span is an amplifier, not just a filter: ReportService::monthBuckets()
     * emits one bucket per calendar month the period touches and then does a
     * per-bucket lookup, so `?date_from=1900-01-01&date_to=2400-01-01` turns a
     * single authenticated GET into ~6,000 buckets of work and payload. Ten
     * years caps that at ~121 buckets — a table a human can actually read —
     * while still covering the longest genuinely useful query, since
     * cooperatives are only required to retain books of account for ten years.
     * Anything wider is a typo or a probe, and a 422 is a better answer to
     * both than a 40x-size response.
     *
     * This is defence in depth, not the only control: the whole `api` group is
     * already throttled to 60 requests/minute per user (per IP when anonymous)
     * via bootstrap/app.php and AppServiceProvider. The throttle bounds how
     * OFTEN a report can be asked for; this bounds how much work any single
     * permitted request can buy, which is the half the throttle cannot see.
     */
    private const MAX_SPAN_YEARS = 10;

    /**
     * Validate the filter parameters the reports share.
     *
     * Every report funnels its query string through here so a malformed date
     * comes back as a 422 the client can act on, instead of reaching
     * `Carbon::parse()` and surfacing as a 500.
     *
     * @param  array<string, array<int, string>>  $extra  Endpoint-specific rules.
     * @return array<string, mixed>
     */
    private function reportFilters(array $extra = []): array
    {
        $filters = request()->validate(array_merge([
            'date' => ['nullable', 'date'],
            'as_of_date' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date'],
            'date_to' => array_values(array_filter([
                'nullable',
                'date',
                // Only a cross-field rule when there is a field to compare to.
                request()->filled('date_from') ? 'after_or_equal:date_from' : null,
            ])),
            // `min:1` is not cosmetic, it is the same falsy hole the loans list
            // closed. 0 is a valid integer that no row can carry, and every
            // consumer of these two filters in ReportService gates on
            // `when($filters['branch_id'] ?? null, ...)` — truthiness, not
            // presence. `?branch_id=0` therefore validated, reached ~30 `when()`
            // calls that treat 0 as absent, and answered a question about one
            // branch with the whole cooperative's figures. `?loan_id=0` does the
            // same to the repayments export. Rejecting it at the boundary is the
            // fix that covers all ~20 endpoints at once; see
            // LoanController::index() for the original write-up.
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'loan_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ], $extra));

        $this->assertSpanWithinCap($filters);

        // Decision B2 (least privilege): a caller without `branches:view_all`
        // reports on their own branches only. A `branch_id` outside them is
        // refused (403); without one, every branch they hold is included
        // (`branch_ids`, read by ReportService::branchScope()). Set here, so
        // every report, its row details, its totals and its CSV export share
        // one scope. Null for `branches:view_all`: the whole organisation.
        $access = app(BranchAccess::class);
        $access->authorizeBranchFilter(request()->user(), $filters['branch_id'] ?? null);
        $filters['branch_ids'] = $access->for(request()->user());

        return $filters;
    }

    /**
     * Reject a reporting period wider than self::MAX_SPAN_YEARS with a 422.
     *
     * Applied here rather than per report so every endpoint inherits it, and
     * measured on the period the service will actually RESOLVE, not on the raw
     * parameters — otherwise the cap is bypassed by simply omitting an end.
     * The defaults below therefore mirror ReportService::resolveDateRange(),
     * resolveOpenEndedRange() and resolveAsOfDate() exactly, including their
     * swap of a reversed range, so `?date_from=2400-01-01&date_to=1900-01-01`
     * is measured as the 500-year period it becomes and not as a negative one.
     *
     * A period with no `date_from` at all is not capped here: that is the
     * share capital reports' "since inception" mode, whose buckets are
     * anchored on the earliest row actually in the ledger rather than on
     * anything the caller sent.
     *
     * @param  array<string, mixed>  $filters
     *
     * @throws ValidationException
     */
    private function assertSpanWithinCap(array $filters): void
    {
        $legacy = ! empty($filters['date']) ? Carbon::parse($filters['date']) : null;

        $from = ! empty($filters['date_from']) ? Carbon::parse($filters['date_from']) : $legacy;
        $to = null;

        foreach (['date_to', 'as_of_date', 'date'] as $key) {
            if (! empty($filters[$key])) {
                $to = Carbon::parse($filters[$key]);
                break;
            }
        }

        if ($from === null && $to === null) {
            return;
        }

        // An absent end resolves to today in every resolver, so the cap has to
        // measure against today too.
        $from ??= Carbon::today();
        $to ??= Carbon::today();

        [$start, $end] = $to->lt($from) ? [$to, $from] : [$from, $to];

        if ($start->copy()->addYears(self::MAX_SPAN_YEARS)->gte($end)) {
            return;
        }

        $message = sprintf(
            'The reporting period may not exceed %d years. Requested %s to %s; narrow the range and try again.',
            self::MAX_SPAN_YEARS,
            $start->toDateString(),
            $end->toDateString(),
        );

        // Attributed to the fields the caller actually sent, so the client can
        // highlight the inputs at fault.
        $fields = array_values(array_filter(
            ['date_from', 'date_to', 'as_of_date', 'date'],
            fn (string $key) => ! empty($filters[$key]),
        ));

        throw ValidationException::withMessages(
            array_fill_keys($fields, $message),
        );
    }

    #[OA\Get(
        path: '/api/reports/statement-of-account/{loan}',
        summary: 'Statement of Account',
        description: 'All transactions, schedule, and balance for a specific loan. Each `amortization_schedule` row also carries the demand letter\'s arrears: `remaining` (`principal`, `interest`, `penalty` still owed, never below 0), `amount_due` (their sum), `days_overdue` (whole days past the due date, today in Asia/Manila; 0 when not yet due) and `is_overdue` (due before today with something still owed). `total_demanded` is every `is_overdue` row\'s `amount_due` plus, when there are any, the insurance premium still owed (a payment takes it first), and `demand_totals` (`principal`, `interest`, `penalty`, `amount_due`) the columns over those rows, with `insurance_owed` the premium included in `total_demanded`. `as_of` is today in the app timezone. `loan.status_label` is "Past Due" when a released or ongoing loan has a Past Due amount above 0, otherwise the app\'s label, and `loan.has_build_up` says whether the loan has a Share Capital Build-Up. `amortization_schedule[].total_due` and `schedule_totals.total_due` include penalty; `build_up_due` is the period\'s build-up and `total_payment` adds it. `total_paid` is the period\'s principal, interest and penalty paid, so `total_due` less `total_paid` is its `amount_remaining`, and `amount_paid` stays principal and interest. `schedule_note` is set when an applied balance adjustment, restructure or term extension restated the schedule, or an extension replaced a period; a row an extension closed or added carries its own `note`, null otherwise. `schedule_totals.balance` is the rows\' `amount_remaining` added up. `transactions[]` lists the loan\'s Ledger rows in its order, each with `kind` (the Ledger\'s), `type` (`release`, `charge`, `payment` or `adjustment`), `description` (alias `particulars`), `debit`, `credit` and `voided`: the release first (a debit of principal and interest), penalty charges, payments, waivers, extension interest and adjustments. A voided payment stays listed with `voided` true, its receipt\'s `amount_paid`, null `debit` and `credit` and zero parts, so it moves no balance and no total; its void has no line of its own. Each line has `payment_method` (label), `reference_number`, `principal_applied`, `interest_applied`, `penalty_applied`, `insurance_applied`, `build_up_applied`, `overpayment_applied` (the excess not credited to build-up, also totalled in `transaction_totals`; positive lowers the balance and negative raises it, except the overpayment, which lowers none, and on every line that is not voided credit minus debit equals them added up), and `principal_balance`, closing on GET /loans/{id}/ledger `closing.principal`; `balance` is its alias for one release. `running_balance` keeps principal plus premium. A line that is not a payment has `amount_paid` 0 and its figure in `credit` when it lowered what is owed, `debit` when it raised it, so `transaction_totals.credit` can exceed `summary.total_paid`, which is the money received. `loan` also carries `product_name`, `released_at`, `member_address`, `branch_name`, `account_officer_name`, `payment_frequency`, `interest_type`, `term_label`, `rate_label` and `extensions_count`. `summary` also carries `principal_paid`, `interest_paid`, `penalty_paid`, `insurance_paid`, `build_up_paid` and `overpayment_paid` (the excess a payment could not credit), which add up to `total_paid`; `insurance_owed`, inside `outstanding_balance`, and `overdue_amount`: the principal, interest and penalty still unpaid on late periods (past the due date plus the loan\'s grace), equal to GET /loans/{id}/summary `overdue_amount`. Each schedule row also carries `amount_remaining`, its principal, interest and penalty still owed. Totals in whole centavos.',
        tags: ['Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'loan', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Statement of account data'),
            new OA\Response(response: 404, description: 'Loan not found'),
        ],
    )]
    public function statementOfAccount(Loan $loan): JsonResponse
    {
        $this->authorize('reports:view');

        return response()->json([
            'data' => $this->reportService->statementOfAccount($loan),
        ]);
    }

    #[OA\Get(
        path: '/api/reports/subsidiary-ledger/{borrower}',
        summary: 'Subsidiary Ledger',
        description: 'All loans with balances and payment history for a member. Each loan row\'s `total_outstanding` is everything still owed (the periods\' unpaid principal, interest and penalty plus the insurance premium owed) and `outstanding_principal` is the unpaid principal only, both in whole centavos and equal to GET /loans/{id}/summary. `outstanding_balance` is deprecated (unpaid principal plus premium owed, never interest or penalty); read `total_outstanding` or `outstanding_principal` instead. `display_status` is `past_due` for a released or ongoing loan past grace, else its status (`status` is unchanged). `overdue_amount` is what the loan\'s late periods (unpaid and past grace) still owe, the Past Due Loans report\'s per-loan figure and GET /loans/{id}/summary `overdue_amount`. `loans_totals` adds up the `loans` table\'s columns (`principal_amount`, `total_paid`, `outstanding_balance`, `total_outstanding`, `outstanding_principal`, `overdue_amount` in whole centavos, `payments_count` as a count). `totals.total_outstanding` equals `loans_totals.total_outstanding`.',
        tags: ['Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'borrower', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Subsidiary ledger data'),
            new OA\Response(response: 404, description: 'Member not found'),
        ],
    )]
    public function subsidiaryLedger(Borrower $borrower): JsonResponse
    {
        $this->authorize('reports:view');

        return response()->json([
            'data' => $this->reportService->subsidiaryLedger($borrower, $this->reportFilters()),
        ]);
    }

    #[OA\Get(
        path: '/api/reports/releases',
        summary: 'List of Releases',
        description: 'Paginated list of released loans with filters. `totals.total_outstanding` is everything owed and `totals.outstanding_principal` the unpaid principal, by the rule of GET /loans; `totals.total_outstanding_balance` is a deprecated alias, principal plus premium.',
        tags: ['Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['released', 'ongoing', 'completed', 'defaulted', 'restructured'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated releases list'),
        ],
    )]
    public function listOfReleases(): AnonymousResourceCollection
    {
        $this->authorize('reports:view');

        $filters = $this->reportFilters([
            'status' => ['nullable', 'string', Rule::in(Loan::EVER_RELEASED_STATUSES)],
        ]);

        return LoanResource::collection($this->reportService->listOfReleases($filters))
            // Totals over the full filtered set — the client caps its fetch at
            // one page and must not add the visible rows up itself.
            ->additional(['totals' => $this->reportService->listOfReleasesTotals($filters)]);
    }

    #[OA\Get(
        path: '/api/reports/repayments',
        summary: 'List of Repayments',
        description: 'Paginated list of repayments with filters',
        tags: ['Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'loan_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['posted', 'voided'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated repayments list'),
        ],
    )]
    public function listOfRepayments(): AnonymousResourceCollection
    {
        $this->authorize('reports:view');

        $filters = $this->reportFilters([
            'status' => ['nullable', 'string', 'in:posted,voided'],
        ]);

        return RepaymentResource::collection($this->reportService->listOfRepayments($filters))
            ->additional(['totals' => $this->reportService->listOfRepaymentsTotals($filters)]);
    }

    #[OA\Get(
        path: '/api/reports/due-past-due',
        summary: 'List of Due / Past Due',
        description: 'Schedules that are due or overdue as of today',
        tags: ['Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'past_due_only', in: 'query', required: false, description: 'When 1, only periods past the due date plus the loan\'s grace (late); the totals follow the same filter. Without it every unpaid period due by today is listed.', schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated due/past-due schedules', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', description: 'One row per unpaid period due by today. Besides the period\'s own figures, each row carries its loan\'s figures, the same on every row of that loan.', items: new OA\Items(properties: [
                    new OA\Property(property: 'loan_total_outstanding', type: 'number', nullable: true, description: 'Everything the loan still owes: its periods\' unpaid principal, interest and penalty plus the insurance premium owed. Equals GET /loans `total_outstanding`. Null only where the server has no figure for the loan (show a dash); 0 is a real figure.'),
                    new OA\Property(property: 'loan_past_due_amount', type: 'number', nullable: true, description: 'The principal, interest and penalty still unpaid on the loan\'s late periods (unpaid and past the due date plus the loan\'s grace). Equals GET /loans/{id}/summary `overdue_amount`; 0 while every period is within grace. Null only where the server has no figure for the loan (show a dash).'),
                    new OA\Property(property: 'loan_last_payment_date', type: 'string', format: 'date', nullable: true, description: 'The `payment_date` of the loan\'s latest posted repayment; voided payments are ignored. Null when there is none.'),
                ])),
                new OA\Property(property: 'totals', type: 'object', properties: [
                    new OA\Property(property: 'count', type: 'integer'),
                    new OA\Property(property: 'overdue_count', type: 'integer'),
                    new OA\Property(property: 'total_principal_due', type: 'number'),
                    new OA\Property(property: 'total_interest_due', type: 'number'),
                    new OA\Property(property: 'total_penalty', type: 'number'),
                    new OA\Property(property: 'total_due', type: 'number', description: 'Principal, interest and penalty, as on each row.'),
                    new OA\Property(property: 'total_paid', type: 'number', description: 'Principal, interest and penalty paid; `total_due` less this is `total_balance`.'),
                    new OA\Property(property: 'total_balance', type: 'number'),
                    new OA\Property(property: 'total_outstanding', type: 'number', description: 'Everything owed by the distinct loans the filter matches, each loan once.'),
                    new OA\Property(property: 'past_due_amount', type: 'number', description: 'The late-and-past-grace amount owed by the distinct loans the filter matches, each loan once.'),
                ]),
                new OA\Property(property: 'meta', type: 'object'),
            ])),
        ],
    )]
    public function listOfDuePastDue(): JsonResponse
    {
        $this->authorize('reports:view');

        $filters = $this->reportFilters(['past_due_only' => ['nullable', 'boolean']]);
        $results = $this->reportService->listOfDuePastDue($filters);
        $today = Carbon::today();
        $loanFigures = $this->reportService->duePastDueLoanFigures(
            $results->getCollection()->pluck('loan_id')->all(),
        );

        return response()->json([
            'data' => $results->getCollection()->map(function ($s) use ($today, $loanFigures) {
                $dueDate = $s->due_date->copy()->startOfDay();

                // `loan` is eager loaded whole by ReportService::listOfDuePastDue(),
                // so reading grace off it costs no query per row. Null-safe for
                // the same reason every other `$s->loan?->` below is: the
                // relation is nullable in PHP even though the column is not.
                $daysLate = $this->daysLate($dueDate, $s->loan?->grace_period_days, $today);

                $amountRemaining = round(
                    max(0, (float) $s->principal_due - (float) $s->principal_paid)
                    + max(0, (float) $s->interest_due - (float) $s->interest_paid)
                    + max(0, (float) $s->penalty_amount - (float) $s->penalty_paid),
                    2,
                );

                return [
                    'id' => $s->id,
                    'loan_id' => $s->loan_id,
                    'loan_account_number' => $s->loan?->loan_account_number,
                    'borrower_name' => $s->loan?->borrower?->full_name,
                    'borrower_code' => $s->loan?->borrower?->borrower_code,
                    'branch_name' => $s->loan?->branch?->name,
                    'period_number' => $s->period_number,
                    'due_date' => $dueDate->toDateString(),
                    // Zero for a schedule due today — due is not the same as
                    // late. Without this the client had nothing to count and
                    // reported an Overdue Count of 0 forever.
                    //
                    // The same rule, widened from one day to the loan's
                    // contractual grace window: `grace_period_days` is printed
                    // on the promissory note and the disclosure statement, so a
                    // borrower inside it is not late and must not be counted or
                    // called as if they were. Measured from grace EXPIRY, not
                    // from the due date, so the first figure a row ever shows is
                    // 1 rather than `grace + 1`.
                    //
                    // The row itself stays in the report. Grace governs
                    // lateness, not owed-ness — the money is due and collections
                    // have to see it. That is duePastDueQuery()'s decision and
                    // it is deliberate; this field is where the distinction is
                    // drawn, which is exactly what the docblock there says.
                    //
                    // See self::daysLate() for where the line is drawn and who
                    // else draws it the same way.
                    'days_overdue' => $daysLate,
                    'days_past_due' => $daysLate,
                    'principal_due' => (float) $s->principal_due,
                    'interest_due' => (float) $s->interest_due,
                    'penalty_amount' => (float) $s->penalty_amount,
                    // Principal, interest and penalty, as the Statement's
                    // schedule states it.
                    'total_due' => $this->dueWithPenalty($s),
                    'principal_paid' => (float) $s->principal_paid,
                    'interest_paid' => (float) $s->interest_paid,
                    'penalty_paid' => (float) $s->penalty_paid,
                    // The row's Paid: the parts `total_due` is made of, as
                    // `totals.total_paid` adds up, so `total_due` less this is
                    // `amount_remaining`.
                    'amount_paid' => round((float) $s->principal_paid + (float) $s->interest_paid + (float) $s->penalty_paid, 2),
                    'amount_remaining' => $amountRemaining,
                    'balance' => $amountRemaining,
                    'status' => $s->status,
                    // The row's loan as a whole, the same on every row of
                    // that loan. See ReportService::duePastDueLoanFigures().
                    'loan_total_outstanding' => $loanFigures[$s->loan_id]['loan_total_outstanding'] ?? null,
                    'loan_past_due_amount' => $loanFigures[$s->loan_id]['loan_past_due_amount'] ?? null,
                    'loan_last_payment_date' => $loanFigures[$s->loan_id]['loan_last_payment_date'] ?? null,
                ];
            }),
            'totals' => $this->reportService->listOfDuePastDueTotals($filters),
            'meta' => [
                'current_page' => $results->currentPage(),
                'last_page' => $results->lastPage(),
                'per_page' => $results->perPage(),
                'total' => $results->total(),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/reports/loan-balance-summary',
        summary: 'Loan Balance Summary',
        description: 'Aggregate portfolio, outstanding, and overdue amounts by branch. `total_outstanding` is everything owed (principal, interest, penalty, premium) and `outstanding_principal` the unpaid principal, on the collectible loans (released, ongoing, defaulted) by the rule of GET /loans `meta.totals`, so they agree with the loan pages to the centavo, and with no date range `outstanding_principal` equals the Dashboard\'s `total_portfolio`. `by_branch[]` and `by_branch_totals` carry both too. `outstanding_balance` is a deprecated alias with its old meaning, principal plus premium.',
        tags: ['Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Loan balance summary'),
        ],
    )]
    public function loanBalanceSummary(): JsonResponse
    {
        $this->authorize('reports:view');

        return response()->json([
            'data' => $this->reportService->loanBalanceSummary($this->reportFilters()),
        ]);
    }

    #[OA\Get(
        path: '/api/reports/daily-collection',
        summary: 'Daily collection report',
        tags: ['Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date', in: 'query', required: false, description: 'Legacy single-day parameter; superseded by date_from/date_to.', schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [new OA\Response(response: 200, description: 'Daily collection summary, counted by period: `total_collected` is the principal and interest paid on the periods due in the range, capped per period (penalty never counts), so `collection_rate` never exceeds 100. Also `collected_in_full`, `partially_collected`, `counts` {collected, partial, pending}, and the cash side: `penalty_collected` and `total_received` (posted receipts in the range, less the insurance premium they collected first; equals Cash Flow\'s `inflows.repayments.total` less `inflows.repayments.insurance`).')],
    )]
    public function dailyCollection(): JsonResponse
    {
        $this->authorize('reports:view');

        return response()->json(['data' => $this->reportService->dailyCollection($this->reportFilters())]);
    }

    #[OA\Get(
        path: '/api/reports/income',
        summary: 'Income report',
        tags: ['Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'loan_id', in: 'query', required: false, description: 'One loan only: its interest and penalty collected and its own fee income.', schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [new OA\Response(response: 200, description: 'Income breakdown. `service_fee` and `other_fees` are the deductions recorded at release of the loans released in the period, classified as the release journal classifies them (4040 and 4080); `total` adds them to `interest_income`, `processing_fees` and `penalty_income`.')],
    )]
    public function incomeReport(): JsonResponse
    {
        $this->authorize('reports:view');

        return response()->json(['data' => $this->reportService->incomeReport($this->reportFilters())]);
    }

    #[OA\Get(
        path: '/api/reports/income/by-loan',
        summary: 'Income by loan account',
        description: 'What GET /api/reports/income adds up, by loan: one row per loan with a posted repayment or fee income '
            .'in the period, ordered by `total_income` desc then `loan_id` asc, paginated over loans (`meta.total` counts '
            .'loans). Each row\'s `total_income` is its interest, penalty, processing fee, service fee and other fees. '
            .'`meta.totals` (also sent as `totals`) covers the whole filtered set: its `interest_income`, `penalty_income`, '
            .'`processing_fee`, `service_fee`, `other_fees` and `total_income` equal the Income report\'s `interest_income`, '
            .'`penalty_income`, `processing_fees`, `service_fee`, `other_fees` and `total`, and every row added up equals them '
            .'too. `payments` equals the List of Repayments `totals.count`, for the same filters. Service and other fees are the '
            .'deductions recorded at release that the release journal credits to 4040 and 4080; taxes, notarial fees, '
            .'insurance premium and share capital are never income.',
        tags: ['Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'loan_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15, maximum: 1000, minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated per-loan income rows with totals', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/IncomeByLoanRow')),
                new OA\Property(property: 'links', type: 'object'),
                new OA\Property(property: 'meta', type: 'object'),
                new OA\Property(property: 'totals', type: 'object', properties: [
                    new OA\Property(property: 'count', type: 'integer', description: 'Loans over the whole filtered set; equals `meta.total`.'),
                    new OA\Property(property: 'payments', type: 'integer'),
                    new OA\Property(property: 'interest_income', type: 'number'),
                    new OA\Property(property: 'penalty_income', type: 'number'),
                    new OA\Property(property: 'processing_fee', type: 'number'),
                    new OA\Property(property: 'service_fee', type: 'number'),
                    new OA\Property(property: 'other_fees', type: 'number'),
                    new OA\Property(property: 'total_income', type: 'number'),
                ]),
            ])),
            new OA\Response(response: 403, description: 'Missing reports:view'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function incomeByLoan(): AnonymousResourceCollection
    {
        $this->authorize('reports:view');

        $report = $this->reportService->incomeByLoanReport($this->reportFilters());

        return IncomeByLoanResource::collection($report['page'])->additional([
            'totals' => $report['totals'],
            'meta' => ['totals' => $report['totals']],
        ]);
    }

    #[OA\Get(
        path: '/api/reports/aging',
        summary: 'Aging report',
        tags: ['Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, description: 'Used as the as-of date when supplied.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'as_of_date', in: 'query', required: false, description: 'Legacy as-of parameter; superseded by date_to.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'Aging buckets. Each bucket carries `share_percent`: its amount as a percentage of `total.amount`, from whole centavos, rounded half up to 2 places; null when the total is 0. `total.share_percent` is 100, or null when the total is 0.')],
    )]
    public function agingReport(): JsonResponse
    {
        $this->authorize('reports:view');

        return response()->json(['data' => $this->reportService->agingReport($this->reportFilters())]);
    }

    #[OA\Get(
        path: '/api/reports/borrowers',
        summary: 'Borrower report',
        description: 'The Member Report\'s cards. `total_active_borrowers`: members with at least one collectible loan as of `date_to`. `new_borrowers`: members created in the period. `repeat_borrowers`: members with at least 2 ever-released loans. GET /api/reports/borrowers/list states the same counts and lists the members behind them.',
        tags: ['Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [new OA\Response(response: 200, description: 'Borrower statistics')],
    )]
    public function borrowerReport(): JsonResponse
    {
        $this->authorize('reports:view');

        return response()->json(['data' => $this->reportService->borrowerReport($this->reportFilters())]);
    }

    #[OA\Get(
        path: '/api/reports/borrowers/list',
        summary: 'Members behind the Member Report\'s cards',
        description: 'Every member who is active or new, by name as displayed then id, paginated over members. `meta.counts` '
            .'(`active`, `new`, `repeat`) equal the cards of GET /api/reports/borrowers for the same filters. A repeat member who '
            .'is neither active nor new is on the `repeat` count and not in the rows. `outstanding` is everything still owed today '
            .'on the loans `active_loans` counts, with the meaning of `total_outstanding`.',
        tags: ['Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15, maximum: 1000, minimum: 1)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1, minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated members with the cards\' counts', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'borrower_id', type: 'integer'),
                    new OA\Property(property: 'borrower_code', type: 'string', nullable: true),
                    new OA\Property(property: 'borrower_name', type: 'string'),
                    new OA\Property(property: 'branch_name', type: 'string', nullable: true),
                    new OA\Property(property: 'is_active', type: 'boolean'),
                    new OA\Property(property: 'is_new', type: 'boolean'),
                    new OA\Property(property: 'is_repeat', type: 'boolean'),
                    new OA\Property(property: 'active_loans', type: 'integer'),
                    new OA\Property(property: 'loans_released', type: 'integer'),
                    new OA\Property(property: 'outstanding', type: 'number'),
                    new OA\Property(property: 'last_release_date', type: 'string', format: 'date', nullable: true),
                ], type: 'object')),
                new OA\Property(property: 'links', type: 'object'),
                new OA\Property(property: 'meta', type: 'object', properties: [
                    new OA\Property(property: 'total', type: 'integer'),
                    new OA\Property(property: 'counts', type: 'object', properties: [
                        new OA\Property(property: 'active', type: 'integer'),
                        new OA\Property(property: 'new', type: 'integer'),
                        new OA\Property(property: 'repeat', type: 'integer'),
                    ]),
                ]),
            ])),
            new OA\Response(response: 403, description: 'Missing reports:view, or a branch outside the caller\'s'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function borrowerList(): AnonymousResourceCollection
    {
        $this->authorize('reports:view');

        $filters = $this->reportFilters();

        return JsonResource::collection($this->reportService->memberList($filters))
            ->additional(['meta' => ['counts' => $this->reportService->memberCounts($filters)]]);
    }

    #[OA\Get(
        path: '/api/reports/borrowers/released',
        summary: 'Borrowers with loans released',
        description: 'The loans GET /api/reports/releases lists, grouped by borrower: one row per borrower, ordered by the '
            .'borrower\'s name as displayed then `borrower_id` asc, paginated over borrowers (`meta.total` counts '
            .'borrowers). `totals` covers the whole filtered set: its `loan_count` and `total_principal` equal the List of '
            .'Releases `totals.count` and `totals.total_principal` for the same filters.',
        tags: ['Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15, maximum: 1000, minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated per-borrower release rows with totals', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/BorrowerReleasedRow')),
                new OA\Property(property: 'links', type: 'object'),
                new OA\Property(property: 'meta', type: 'object'),
                new OA\Property(property: 'totals', type: 'object', properties: [
                    new OA\Property(property: 'count', type: 'integer', description: 'Borrowers over the whole filtered set; equals `meta.total`.'),
                    new OA\Property(property: 'loan_count', type: 'integer'),
                    new OA\Property(property: 'total_principal', type: 'number'),
                ]),
            ])),
            new OA\Response(response: 403, description: 'Missing reports:view'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function borrowersReleased(): AnonymousResourceCollection
    {
        $this->authorize('reports:view');

        $filters = $this->reportFilters();

        return BorrowerReleasedResource::collection($this->reportService->borrowersReleased($filters))
            ->additional(['totals' => $this->reportService->borrowersReleasedTotals($filters)]);
    }

    #[OA\Get(
        path: '/api/reports/disbursements',
        summary: 'Disbursement report',
        tags: ['Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'Disbursement statistics')],
    )]
    public function disbursementReport(): JsonResponse
    {
        $this->authorize('reports:view');

        return response()->json(['data' => $this->reportService->disbursementReport($this->reportFilters())]);
    }

    #[OA\Get(
        path: '/api/reports/cash-flow',
        summary: 'Cash Flow / Cash Position',
        description: 'Cash in (posted repayments split by allocation, the insurance premium they collected first as `inflows.repayments.insurance` and per branch `inflow_insurance`, both inside the totals, plus share capital paid in on its own) versus cash out (net proceeds released, plus share capital paid out on its own) for a period, with a per-branch breakdown. Deductions are reported separately as non-cash, share capital withheld at release under `non_cash.share_capital_at_release`. A payment\'s share capital build-up is already inside the repayment line (its overpayment), so its ledger credits and any void reversals are in no cash total: they are reported under `share_capital.build_up_from_payments` (net), `build_up_credit` and `build_up_debit`. The Share Capital report\'s credits for the same period and branch = `share_capital.credit` + `share_capital.build_up_credit` + `non_cash.share_capital_at_release`; its debits = `share_capital.debit` + `share_capital.build_up_debit`.',
        tags: ['Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date', in: 'query', required: false, description: 'Legacy single-day parameter; superseded by date_from/date_to.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, description: 'Scopes loan cash by the loan\'s branch and share capital by the member\'s branch, matching the Share Capital report. See share_capital.branch_scope (borrower_branch when filtered, organisation when not). Share capital stays out of by_branch either way.', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'Cash flow for the period')],
    )]
    public function cashFlow(): JsonResponse
    {
        $this->authorize('reports:view');

        return response()->json(['data' => $this->reportService->cashFlow($this->reportFilters())]);
    }

    #[OA\Get(
        path: '/api/reports/collection-efficiency',
        summary: 'Collection Efficiency',
        description: 'Amount due versus amount collected for a period, overall and segmented by branch and by month.',
        tags: ['Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date', in: 'query', required: false, description: 'Legacy single-day parameter; superseded by date_from/date_to.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'Collection efficiency with branch and monthly breakdowns. Overall figures equal Daily Collection\'s for the same filters; each branch row and due-month bucket is counted by period the same way, so no rate exceeds 100.')],
    )]
    public function collectionEfficiency(): JsonResponse
    {
        $this->authorize('reports:view');

        return response()->json(['data' => $this->reportService->collectionEfficiency($this->reportFilters())]);
    }

    #[OA\Get(
        path: '/api/reports/portfolio-by-product',
        summary: 'Loan Portfolio by Product',
        description: 'Loan count, amount released, `total_outstanding` (everything owed), `outstanding_principal` (unpaid principal), `outstanding` (a deprecated alias, principal plus premium), average rate, overdue amount and PAR ratio per loan product. Date filters apply to release date, matching the Loan Balance Summary.',
        tags: ['Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', required: false, description: 'Filters on release date.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, description: 'Filters on release date.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'Portfolio broken down by loan product')],
    )]
    public function portfolioByProduct(): JsonResponse
    {
        $this->authorize('reports:view');

        return response()->json(['data' => $this->reportService->portfolioByProduct($this->reportFilters())]);
    }

    #[OA\Get(
        path: '/api/reports/share-capital',
        summary: 'Share Capital Report',
        description: 'Opening balance, period credits and debits, closing balance, monthly movement, per-member holdings and subscription totals. Omit date_from to report from inception. `by_member` requires the reports:export permission; without it the field is null and `by_member_omitted` explains why, while every aggregate figure is unchanged.',
        tags: ['Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', required: false, description: 'Optional. Everything before this date forms the opening balance; omit to report from inception.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, description: 'Defaults to today.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'as_of_date', in: 'query', required: false, description: 'Legacy as-of parameter; superseded by date_to.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, description: 'The ledger has no branch column, so this filters on the member\'s branch.', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'Share capital movement and balances')],
    )]
    public function shareCapital(): JsonResponse
    {
        $this->authorize('reports:view');

        // `by_member` is the whole membership roster with each member's
        // balance. `reports:view` is held by every seeded role, so gate the
        // roster on `reports:export` and leave the aggregates open to all.
        // Resolved here, server-side — reportFilters() strips unknown keys, so
        // this can never be influenced by the query string.
        $includeMembers = (bool) request()->user()?->can('reports:export');

        return response()->json([
            'data' => $this->reportService->shareCapital($this->reportFilters(), $includeMembers),
        ]);
    }

    #[OA\Get(
        path: '/api/reports/performance',
        summary: 'Officer / Branch Performance',
        description: '`total_outstanding` is everything owed, `outstanding_principal` the unpaid principal, and `outstanding` a deprecated alias, principal plus premium. Loans released and amounts collected for the period (`collected` leaves out the insurance premium payments collected, stated as `insurance_collected`), alongside outstanding portfolio, overdue amount, PAR ratio and active borrowers as of today, grouped by account officer and mirrored by branch. Loans with no officer appear as an Unassigned row.',
        tags: ['Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', required: false, description: 'Bounds released and collected figures only; omit for all time.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, description: 'Bounds released and collected figures only; omit for all time.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'Performance by account officer and by branch')],
    )]
    public function performance(): JsonResponse
    {
        $this->authorize('reports:view');

        return response()->json(['data' => $this->reportService->performance($this->reportFilters())]);
    }

    #[OA\Get(
        path: '/api/reports/provisioning',
        summary: 'Loan Loss Provisioning',
        description: 'Required allowance per aging bucket, derived from the Aging report so the bucket boundaries can never drift, multiplied by the policy provision rates (5 / 15 / 25 / 50 %).',
        tags: ['Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'date_to', in: 'query', required: false, description: 'Used as the as-of date when supplied.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'as_of_date', in: 'query', required: false, description: 'Legacy as-of parameter; superseded by date_to.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'Required allowance by aging bucket')],
    )]
    public function provisioning(): JsonResponse
    {
        $this->authorize('reports:view');

        return response()->json(['data' => $this->reportService->provisioning($this->reportFilters())]);
    }

    #[OA\Get(
        path: '/api/reports/share-capital-statement/{borrower}',
        summary: 'Share Capital Statement',
        description: 'One member\'s complete share capital ledger with a running balance (`entries[].running_balance`), opening and closing balances and period totals (`totals.credits`, `totals.debits`, also as `total_credit` and `total_debit` beside `closing_balance`). Unpaginated: this feeds a printable certificate.',
        tags: ['Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'borrower', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, description: 'Optional. Everything before this date forms the opening balance; omit to report from inception.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, description: 'Defaults to today.', schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Share capital statement for the member'),
            new OA\Response(response: 404, description: 'Borrower not found'),
        ],
    )]
    public function shareCapitalStatement(Borrower $borrower): JsonResponse
    {
        $this->authorize('reports:view');

        return response()->json([
            'data' => $this->reportService->shareCapitalStatement($borrower, $this->reportFilters()),
        ]);
    }

    // ── CSV Exports ──────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/reports/releases/export',
        summary: 'Export releases as CSV',
        tags: ['Reports'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'CSV file download')],
    )]
    public function exportReleases(): StreamedResponse
    {
        $this->authorize('reports:export');

        $query = $this->reportService
            ->releasesQuery($this->reportFilters([
                'status' => ['nullable', 'string', Rule::in(Loan::EVER_RELEASED_STATUSES)],
            ]))
            ->with('borrower', 'loanProduct');

        return $this->streamCsv('releases.csv', [
            'Loan #', 'Member', 'Product', 'Principal', 'Interest Rate', 'Term', 'Released', 'Status',
        ], $query->lazy(500)->map(fn ($l) => [
            $l->loan_account_number ?? $l->application_number,
            $l->borrower?->full_name ?? '',
            $l->loanProduct?->name ?? '',
            $l->principal_amount,
            $l->interest_rate.'%',
            $l->term.' '.$l->term_unit->value,
            $l->released_at?->toDateString() ?? '',
            $l->status,
        ]));
    }

    #[OA\Get(
        path: '/api/reports/repayments/export',
        summary: 'Export repayments as CSV',
        tags: ['Reports'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'CSV file download')],
    )]
    public function exportRepayments(): StreamedResponse
    {
        $this->authorize('reports:export');

        // Same builder as the preview, so the CSV cannot include voided
        // receipts the screen excluded.
        $query = $this->reportService
            ->repaymentsQuery($this->reportFilters([
                'status' => ['nullable', 'string', 'in:posted,voided'],
            ]))
            ->with('loan.borrower');

        return $this->streamCsv('repayments.csv', [
            'Receipt #', 'Member', 'Loan #', 'Date', 'Amount', 'Insurance Premium', 'Method', 'Status',
        ], $query->lazy(500)->map(fn ($r) => [
            $r->receipt_number,
            $r->loan?->borrower?->full_name ?? '',
            $r->loan?->loan_account_number ?? '',
            $r->payment_date?->toDateString() ?? '',
            $r->amount_paid,
            // The part of Amount that paid the insurance premium owed, first.
            $r->insurance_applied,
            $r->method ?? 'cash',
            $r->status,
        ]));
    }

    /**
     * A Due/Past Due row's Total Due: principal, interest and penalty, on the
     * preview and the export alike.
     */
    private function dueWithPenalty(AmortizationSchedule $schedule): float
    {
        return round((float) $schedule->total_due + (float) ($schedule->penalty_amount ?? 0), 2);
    }

    /**
     * How many days late a schedule is, measured from the expiry of its loan's
     * grace period rather than from the due date, and floored at zero.
     *
     * `grace_period_days` is printed on the promissory note and the disclosure
     * statement. A borrower inside that window is not late and must not be
     * counted or chased as if they were — this is the report's long-standing
     * "due today is not late" rule widened from one day to the contractual
     * window. The row itself is unaffected: grace governs lateness, not
     * owed-ness, so the schedule stays in a report collections rely on.
     *
     * One method for the preview and the export because those two have drifted
     * apart before — they disagreed on loan statuses and on the cutoff, and the
     * CSV a manager downloaded did not match the screen they downloaded it
     * from. The boundary itself lives in AmortizationSchedule::pastGraceCutoff(),
     * shared in turn with the SQL twin pastGraceSql() that
     * ReportService::listOfDuePastDueTotals() uses for `overdue_count`. All
     * three therefore answer "is this row late?" identically, which is what
     * stops a page of rows reading 0 days overdue above a totals block claiming
     * several are late.
     *
     * Grace 0 collapses the cutoff back to `$asOf`, reproducing the original
     * due-date comparison exactly — which is the regression check.
     */
    private function daysLate(?Carbon $dueDate, ?int $graceDays, Carbon $asOf): int
    {
        if ($dueDate === null) {
            return 0;
        }

        $cutoff = AmortizationSchedule::pastGraceCutoff($graceDays, $asOf);

        return $dueDate->lt($cutoff) ? (int) $dueDate->diffInDays($cutoff) : 0;
    }

    #[OA\Get(
        path: '/api/reports/due-past-due/export',
        summary: 'Export due/past-due schedules as CSV',
        tags: ['Reports'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'CSV file download')],
    )]
    public function exportDuePastDue(): StreamedResponse
    {
        $this->authorize('reports:export');

        // Same builder as the preview. These two used to disagree on both the
        // loan statuses and the cutoff (`now()` vs `Carbon::today()`), so the
        // CSV did not match the screen it was exported from.
        $query = $this->reportService
            ->duePastDueQuery($this->reportFilters(['past_due_only' => ['nullable', 'boolean']]))
            ->with('loan.borrower');

        $today = Carbon::today();

        return $this->streamCsv('due-past-due.csv', [
            'Member', 'Loan #', 'Due Date', 'Days Overdue', 'Principal Due', 'Interest Due', 'Penalty', 'Total Due', 'Status',
        ], $query->lazy(500)->map(function ($s) use ($today) {
            $dueDate = $s->due_date?->copy()->startOfDay();

            return [
                $s->loan?->borrower?->full_name ?? '',
                $s->loan?->loan_account_number ?? '',
                $dueDate?->toDateString() ?? '',
                // Through the same helper as the preview. This column and the
                // preview's `days_overdue` are asserted equal row for row by
                // test_due_past_due_export_matches_the_preview_row_for_row(),
                // which is what caught this when only the preview was made
                // grace-aware.
                $this->daysLate($dueDate, $s->loan?->grace_period_days, $today),
                $s->principal_due,
                $s->interest_due,
                $s->penalty_amount ?? 0,
                $this->dueWithPenalty($s),
                $s->status,
            ];
        }));
    }
}
