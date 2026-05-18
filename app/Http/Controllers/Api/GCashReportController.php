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
        description: 'Sums charge_amount across non-pending transactions whose transaction_date falls within [start_date, end_date]. Pending Cash Ins are deferred income and excluded.',
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

        $report = $this->gcash->incomeReport($validated['start_date'], $validated['end_date']);

        return response()->json([
            'data' => [
                'total_income' => $report['total_income'],
                'start_date' => $report['start_date'],
                'end_date' => $report['end_date'],
                'transactions' => GCashTransactionResource::collection(
                    $report['transactions']->load(['borrower', 'transactor']),
                ),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/gcash/reports/pending',
        summary: 'List pending Cash In transactions awaiting payment',
        tags: ['GCash'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'List with days_pending per row'),
            new OA\Response(response: 403, description: 'Missing gcash:view permission'),
        ],
    )]
    public function pending(): AnonymousResourceCollection
    {
        $this->authorize('gcash:view');

        return GCashTransactionResource::collection(
            $this->gcash->pendingReport()->load(['borrower', 'transactor']),
        );
    }
}
