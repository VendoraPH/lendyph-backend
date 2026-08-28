<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Traits\CsvExportTrait;
use App\Http\Controllers\Controller;
use App\Http\Resources\LoanResource;
use App\Http\Resources\RepaymentResource;
use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Loan;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
        description: 'All transactions, schedule, and balance for a specific loan',
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
        description: 'All loans with balances and payment history for a borrower',
        tags: ['Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'borrower', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Subsidiary ledger data'),
            new OA\Response(response: 404, description: 'Borrower not found'),
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
        description: 'Paginated list of released loans with filters',
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
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated due/past-due schedules'),
        ],
    )]
    public function listOfDuePastDue(): JsonResponse
    {
        $this->authorize('reports:view');

        $filters = $this->reportFilters();
        $results = $this->reportService->listOfDuePastDue($filters);
        $today = Carbon::today();

        return response()->json([
            'data' => $results->getCollection()->map(function ($s) use ($today) {
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
                    'total_due' => (float) $s->total_due,
                    'principal_paid' => (float) $s->principal_paid,
                    'interest_paid' => (float) $s->interest_paid,
                    'amount_remaining' => $amountRemaining,
                    'balance' => $amountRemaining,
                    'status' => $s->status,
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
        description: 'Aggregate portfolio, outstanding, and overdue amounts by branch',
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
        responses: [new OA\Response(response: 200, description: 'Daily collection summary')],
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
        ],
        responses: [new OA\Response(response: 200, description: 'Income breakdown')],
    )]
    public function incomeReport(): JsonResponse
    {
        $this->authorize('reports:view');

        return response()->json(['data' => $this->reportService->incomeReport($this->reportFilters())]);
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
        responses: [new OA\Response(response: 200, description: 'Aging buckets')],
    )]
    public function agingReport(): JsonResponse
    {
        $this->authorize('reports:view');

        return response()->json(['data' => $this->reportService->agingReport($this->reportFilters())]);
    }

    #[OA\Get(
        path: '/api/reports/borrowers',
        summary: 'Borrower report',
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
        description: 'Cash in (posted repayments split by allocation, plus share capital credits) versus cash out (net proceeds released, plus share capital debits) for a period, with a per-branch breakdown. Deductions are reported separately as non-cash.',
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
        responses: [new OA\Response(response: 200, description: 'Collection efficiency with branch and monthly breakdowns')],
    )]
    public function collectionEfficiency(): JsonResponse
    {
        $this->authorize('reports:view');

        return response()->json(['data' => $this->reportService->collectionEfficiency($this->reportFilters())]);
    }

    #[OA\Get(
        path: '/api/reports/portfolio-by-product',
        summary: 'Loan Portfolio by Product',
        description: 'Loan count, amount released, outstanding balance, average rate, overdue amount and PAR ratio per loan product. Date filters apply to release date, matching the Loan Balance Summary.',
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
        description: 'Loans released and amounts collected for the period, alongside outstanding portfolio, overdue amount, PAR ratio and active borrowers as of today, grouped by account officer and mirrored by branch. Loans with no officer appear as an Unassigned row.',
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
        description: 'One member\'s complete share capital ledger with a running balance, opening and closing balances and period totals. Unpaginated: this feeds a printable certificate.',
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
            'Loan #', 'Borrower', 'Product', 'Principal', 'Interest Rate', 'Term', 'Released', 'Status',
        ], $query->lazy(500)->map(fn ($l) => [
            $l->loan_account_number ?? $l->application_number,
            $l->borrower?->full_name ?? '',
            $l->loanProduct?->name ?? '',
            $l->principal_amount,
            $l->interest_rate.'%',
            $l->term.' months',
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
            'Receipt #', 'Borrower', 'Loan #', 'Date', 'Amount', 'Method', 'Status',
        ], $query->lazy(500)->map(fn ($r) => [
            $r->receipt_number,
            $r->loan?->borrower?->full_name ?? '',
            $r->loan?->loan_account_number ?? '',
            $r->payment_date?->toDateString() ?? '',
            $r->amount_paid,
            $r->method ?? 'cash',
            $r->status,
        ]));
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
            ->duePastDueQuery($this->reportFilters())
            ->with('loan.borrower');

        $today = Carbon::today();

        return $this->streamCsv('due-past-due.csv', [
            'Borrower', 'Loan #', 'Due Date', 'Days Overdue', 'Principal Due', 'Interest Due', 'Penalty', 'Total Due', 'Status',
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
                $s->total_due,
                $s->status,
            ];
        }));
    }
}
