<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BranchAccess;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class DashboardController extends Controller
{
    public function __construct(private DashboardService $dashboardService) {}

    /**
     * The caller's branches (decision B2): every dashboard figure counts only
     * their branches' loans and payments. Null for `branches:view_all`.
     *
     * @return list<int>|null
     */
    private function branchIds(): ?array
    {
        return app(BranchAccess::class)->for(request()->user());
    }

    #[OA\Get(
        path: '/api/dashboard/stats',
        summary: 'Dashboard KPI stats',
        description: '`total_collected` (month to date, and the `collected` sparkline) counts what posted payments collected for the loans, leaving out the insurance premium a payment collected first; that premium is `insurance_collected`, held for the insurer. Every figure covers the caller\'s branches only (`branches:view_all`: every branch). The `active_loans` sparkline counts released and ongoing loans, like the card. The `overdue` sparkline\'s past points come from the organisation-wide daily snapshots, so they are null for a caller without `branches:view_all`; its last point is always the caller\'s live `overdue_count`.',
        tags: ['Dashboard'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Dashboard KPI metrics with sparklines'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function stats(): JsonResponse
    {
        $this->authorize('dashboard:view');

        return response()->json(['data' => $this->dashboardService->stats($this->branchIds())]);
    }

    #[OA\Get(
        path: '/api/dashboard/collections-trend',
        summary: 'Collections trend time-series',
        description: 'Default is 12 weekly buckets. Pass `?period=month` for 12 monthly buckets or `?period=year` for 5 yearly buckets. Each bucket\'s `value` leaves out the insurance premium payments collected, which is the bucket\'s `insurance_collected`.',
        tags: ['Dashboard'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'period', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['week', 'month', 'year'], default: 'week')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Collections trend'),
        ],
    )]
    public function collectionsTrend(): JsonResponse
    {
        $this->authorize('dashboard:view');

        $period = request('period', 'week');
        if (! in_array($period, ['week', 'month', 'year'], true)) {
            $period = 'week';
        }

        return response()->json($this->dashboardService->collectionsTrend($this->branchIds(), $period));
    }

    #[OA\Get(
        path: '/api/dashboard/daily-dues',
        summary: 'Daily dues with collection status',
        tags: ['Dashboard'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daily dues list with summary. One item per loan: a period an extension or restructure closed early and its rescheduled remainder due the same day are one item, whose `amount_due` and `amount_collected` both include the closed part. A row is `collected` only once the principal and interest paid cover its `total_due` (penalty paid never counts towards it); `amount_collected` is that principal and interest, capped at the amount due. `summary` carries the dashboard pie: `collected_in_full`, `partially_collected`, `uncollected`, `total_collected`, `collection_rate` (never above 100) and `counts` per status.'),
        ],
    )]
    public function dailyDues(): JsonResponse
    {
        $this->authorize('dashboard:view');

        return response()->json($this->dashboardService->dailyDues($this->branchIds(), request('date')));
    }

    #[OA\Get(
        path: '/api/dashboard/recent-transactions',
        summary: 'Recent transactions (payments + releases)',
        tags: ['Dashboard'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10, minimum: 1, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Recent transaction activity feed'),
        ],
    )]
    public function recentTransactions(): JsonResponse
    {
        $this->authorize('dashboard:view');

        $limit = max(1, min((int) request('limit', 10), 100));

        return response()->json($this->dashboardService->recentTransactions($this->branchIds(), $limit));
    }
}
