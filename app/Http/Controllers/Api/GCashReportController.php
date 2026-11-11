<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GCashTransactionResource;
use App\Models\GCashTransaction;
use App\Services\BranchAccess;
use App\Services\GCashService;
use Illuminate\Database\Eloquent\Collection;
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
        description: 'Sums charge_amount across non-pending transactions whose transaction_date falls within [start_date, end_date]. Pending Cash Ins are deferred income and excluded. A caller without branches:view_all sees only their branches\' member transactions plus every walk-in transaction, and the total covers those rows.',
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
        $branchIds = app(BranchAccess::class)->for($request->user());
        $transactions = $this->visible($report['transactions']->load(['borrower', 'nonMember', 'transactor']), $branchIds);

        return response()->json([
            'data' => [
                // A scoped caller's total is the charges on the rows they can
                // see, added in whole centavos.
                'total_income' => $branchIds === null
                    ? $report['total_income']
                    : $transactions->sum(fn (GCashTransaction $t): int => (int) round((float) $t->charge_amount * 100)) / 100,
                'start_date' => $report['start_date'],
                'end_date' => $report['end_date'],
                'transactions' => GCashTransactionResource::collection($transactions),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/gcash/reports/pending',
        summary: 'List pending Cash In transactions awaiting payment',
        tags: ['GCash'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'List with days_pending per row. Each row\'s `branch_id` and `branch_name` are the branch whose books it posts in: the member\'s branch for a member, the recorded branch for a walk-in, and null with "Head office" for a walk-in recorded with no branch. A branch user sees their own members\' rows and every walk-in row, as before.'),
            new OA\Response(response: 403, description: 'Missing gcash:view permission'),
        ],
    )]
    public function pending(): AnonymousResourceCollection
    {
        $this->authorize('gcash:view');

        return GCashTransactionResource::collection($this->visible(
            $this->gcash->pendingReport()->load(['borrower.branch', 'nonMember', 'transactor', 'branch']),
            app(BranchAccess::class)->for(request()->user()),
        ));
    }

    /**
     * The rows a caller may see (decision B2), as GET /gcash/transactions
     * lists them: a member's transaction follows the member's branch, and a
     * walk-in's (no member) belongs to no branch and stays visible. Every row
     * for `branches:view_all` ($branchIds null).
     *
     * @param  Collection<int, GCashTransaction>  $transactions  with `borrower` loaded
     * @param  list<int>|null  $branchIds
     * @return Collection<int, GCashTransaction>
     */
    private function visible(Collection $transactions, ?array $branchIds): Collection
    {
        if ($branchIds === null) {
            return $transactions;
        }

        return $transactions
            ->filter(fn (GCashTransaction $t): bool => $t->borrower_id === null
                || ($t->borrower !== null && in_array((int) $t->borrower->branch_id, $branchIds, true)))
            ->values();
    }
}
