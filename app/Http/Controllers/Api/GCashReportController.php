<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GCashTransactionResource;
use App\Services\GCashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class GCashReportController extends Controller
{
    public function __construct(private GCashService $gcash) {}

    #[OA\Get(
        path: '/api/gcash/reports/income',
        summary: 'Sum of GCash charge income in a date range',
        description: 'Sums charge_amount over the charges earned within [start_date, end_date] (Manila days), on the date the books post 4090: a Cash In on the day it was paid (paid_at), a Cash Out on its transaction_date. Counts them by type (cash_in_count, cash_out_count). A pending Cash In is income in no period until it is paid. A caller without branches:view_all sees only their branches\' member transactions and the walk-in transactions recorded in their branches (a walk-in with no branch needs branches:view_all); the list, total and counts cover those rows.',
        tags: ['GCash'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'start_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'end_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Income report'),
            new OA\Response(response: 403, description: 'Missing gcash:view permission'),
            new OA\Response(response: 422, description: 'Missing or invalid date range'),
        ],
    )]
    public function income(Request $request): JsonResponse
    {
        $this->authorize('gcash:view');

        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $report = $this->gcash->incomeReport($validated['start_date'], $validated['end_date'], $request->user());

        return response()->json([
            'data' => [
                'total_income' => $report['total_income'],
                'cash_in_count' => $report['cash_in_count'],
                'cash_out_count' => $report['cash_out_count'],
                'start_date' => $report['start_date'],
                'end_date' => $report['end_date'],
                'transactions' => GCashTransactionResource::collection($report['transactions']->load(['borrower', 'nonMember', 'transactor'])),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/gcash/reports/pending',
        summary: 'List pending Cash In transactions awaiting payment',
        tags: ['GCash'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'List with days_pending per row. Each row\'s `branch_id` and `branch_name` are the branch whose books it posts in: the member\'s branch for a member, the recorded branch for a walk-in, and null with "Head office" for a walk-in recorded with no branch. A branch user sees their own members\' rows and the walk-in rows recorded in their branches; walk-in rows with no branch need branches:view_all.'),
            new OA\Response(response: 403, description: 'Missing gcash:view permission'),
        ],
    )]
    public function pending(): AnonymousResourceCollection
    {
        $this->authorize('gcash:view');

        return GCashTransactionResource::collection(
            $this->gcash->pendingReport(request()->user())->load(['borrower.branch', 'nonMember', 'transactor', 'branch']),
        );
    }
}
