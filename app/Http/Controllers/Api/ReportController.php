<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Traits\CsvExportTrait;
use App\Http\Controllers\Controller;
use App\Http\Resources\LoanResource;
use App\Http\Resources\RepaymentResource;
use App\Models\Borrower;
use App\Models\Loan;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    use CsvExportTrait;

    public function __construct(private ReportService $reportService) {}

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
            'data' => $this->reportService->subsidiaryLedger(
                $borrower,
                request()->only('date_from', 'date_to'),
            ),
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
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['released', 'ongoing', 'completed'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated releases list'),
        ],
    )]
    public function listOfReleases(): AnonymousResourceCollection
    {
        $this->authorize('reports:view');

        $results = $this->reportService->listOfReleases(
            request()->only('date_from', 'date_to', 'branch_id', 'status', 'per_page'),
        );

        return LoanResource::collection($results);
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

        $results = $this->reportService->listOfRepayments(
            request()->only('date_from', 'date_to', 'branch_id', 'loan_id', 'status', 'per_page'),
        );

        return RepaymentResource::collection($results);
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

        $results = $this->reportService->listOfDuePastDue(
            request()->only('date_from', 'date_to', 'branch_id', 'per_page'),
        );

        return response()->json([
            'data' => $results->getCollection()->map(fn ($s) => [
                'id' => $s->id,
                'loan_id' => $s->loan_id,
                'loan_account_number' => $s->loan?->loan_account_number,
                'borrower_name' => $s->loan?->borrower?->full_name,
                'borrower_code' => $s->loan?->borrower?->borrower_code,
                'branch_name' => $s->loan?->branch?->name,
                'period_number' => $s->period_number,
                'due_date' => $s->due_date->toDateString(),
                'principal_due' => (float) $s->principal_due,
                'interest_due' => (float) $s->interest_due,
                'penalty_amount' => (float) $s->penalty_amount,
                'total_due' => (float) $s->total_due,
                'principal_paid' => (float) $s->principal_paid,
                'interest_paid' => (float) $s->interest_paid,
                'amount_remaining' => round(
                    max(0, (float) $s->principal_due - (float) $s->principal_paid)
                    + max(0, (float) $s->interest_due - (float) $s->interest_paid)
                    + max(0, (float) $s->penalty_amount - (float) $s->penalty_paid),
                    2,
                ),
                'status' => $s->status,
            ]),
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
            'data' => $this->reportService->loanBalanceSummary(
                request()->only('date_from', 'date_to', 'branch_id'),
            ),
        ]);
    }

    #[OA\Get(
        path: '/api/reports/daily-collection',
        summary: 'Daily collection report',
        tags: ['Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [new OA\Response(response: 200, description: 'Daily collection summary')],
    )]
    public function dailyCollection(): JsonResponse
    {
        $this->authorize('reports:view');

        return response()->json(['data' => $this->reportService->dailyCollection(request()->all())]);
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

        return response()->json(['data' => $this->reportService->incomeReport(request()->only('date_from', 'date_to'))]);
    }

    #[OA\Get(
        path: '/api/reports/aging',
        summary: 'Aging report',
        tags: ['Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'as_of_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'Aging buckets')],
    )]
    public function agingReport(): JsonResponse
    {
        $this->authorize('reports:view');

        return response()->json(['data' => $this->reportService->agingReport(request()->only('as_of_date', 'branch_id'))]);
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

        return response()->json(['data' => $this->reportService->borrowerReport(request()->only('date_from', 'date_to'))]);
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

        return response()->json(['data' => $this->reportService->disbursementReport(request()->only('date_from', 'date_to', 'branch_id'))]);
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

        $loans = $this->reportService->listOfReleases(
            request()->only('date_from', 'date_to', 'branch_id', 'status') + ['per_page' => 10000],
        );

        return $this->streamCsv('releases.csv', [
            'Loan #', 'Borrower', 'Product', 'Principal', 'Interest Rate', 'Term', 'Released', 'Status',
        ], $loans->map(fn ($l) => [
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

        $repayments = $this->reportService->listOfRepayments(
            request()->only('date_from', 'date_to', 'branch_id', 'loan_id', 'status') + ['per_page' => 10000],
        );

        return $this->streamCsv('repayments.csv', [
            'Receipt #', 'Borrower', 'Loan #', 'Date', 'Amount', 'Method', 'Status',
        ], $repayments->map(fn ($r) => [
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

        $schedules = $this->reportService->listOfDuePastDue(
            request()->only('date_from', 'date_to', 'branch_id') + ['per_page' => 10000],
        );

        return $this->streamCsv('due-past-due.csv', [
            'Borrower', 'Loan #', 'Due Date', 'Principal Due', 'Interest Due', 'Penalty', 'Total Due', 'Status',
        ], $schedules->map(fn ($s) => [
            $s->loan?->borrower?->full_name ?? '',
            $s->loan?->loan_account_number ?? '',
            $s->due_date?->toDateString() ?? '',
            $s->principal_due,
            $s->interest_due,
            $s->penalty_amount ?? 0,
            $s->total_due,
            $s->status,
        ]));
    }
}
