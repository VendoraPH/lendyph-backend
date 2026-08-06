<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Traits\CsvExportTrait;
use App\Http\Controllers\Controller;
use App\Http\Resources\LoanResource;
use App\Http\Resources\RepaymentResource;
use App\Models\Borrower;
use App\Models\Loan;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    use CsvExportTrait;

    public function __construct(private ReportService $reportService) {}

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
        return request()->validate(array_merge([
            'date' => ['nullable', 'date'],
            'as_of_date' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date'],
            'date_to' => array_values(array_filter([
                'nullable',
                'date',
                // Only a cross-field rule when there is a field to compare to.
                request()->filled('date_from') ? 'after_or_equal:date_from' : null,
            ])),
            'branch_id' => ['nullable', 'integer'],
            'loan_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ], $extra));
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
                    'days_overdue' => $dueDate->lt($today) ? (int) $dueDate->diffInDays($today) : 0,
                    'days_past_due' => $dueDate->lt($today) ? (int) $dueDate->diffInDays($today) : 0,
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
                $dueDate && $dueDate->lt($today) ? (int) $dueDate->diffInDays($today) : 0,
                $s->principal_due,
                $s->interest_due,
                $s->penalty_amount ?? 0,
                $s->total_due,
                $s->status,
            ];
        }));
    }
}
