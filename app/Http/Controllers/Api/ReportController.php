<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LoanResource;
use App\Http\Resources\RepaymentResource;
use App\Models\Borrower;
use App\Models\Loan;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class ReportController extends Controller
{
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
        $this->authorize('reports.view');

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
        $this->authorize('reports.view');

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
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['released', 'closed'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated releases list'),
        ],
    )]
    public function listOfReleases(): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $this->authorize('reports.view');

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
    public function listOfRepayments(): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $this->authorize('reports.view');

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
        $this->authorize('reports.view');

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
        $this->authorize('reports.view');

        return response()->json([
            'data' => $this->reportService->loanBalanceSummary(
                request()->only('date_from', 'date_to', 'branch_id'),
            ),
        ]);
    }
}
