<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\Executive\ExecutiveBranchesRequest;
use App\Http\Requests\Dashboard\Executive\ExecutiveDashboardRequest;
use App\Http\Requests\Dashboard\Executive\ParLoansRequest;
use App\Services\Dashboard\ExecutiveBranches;
use App\Services\Dashboard\ExecutiveOverview;
use App\Services\Dashboard\LoanScope;
use App\Services\Dashboard\ParLoans;
use Illuminate\Http\JsonResponse;

/**
 * The executive dashboard's cards, branch table and PAR loans (Phase 6
 * EB-D1). Every route needs `dashboard:analytics` (the requests), and each
 * figure its own source's permission (the services).
 */
class ExecutiveDashboardController extends Controller
{
    public function overview(ExecutiveDashboardRequest $request, ExecutiveOverview $overview): JsonResponse
    {
        return response()->json(['data' => $overview->build(
            $request->user(),
            $request->period(),
            $request->reportFilters(),
            $request->branchScope(),
        )]);
    }

    public function branches(ExecutiveBranchesRequest $request, ExecutiveBranches $branches): JsonResponse
    {
        return response()->json(['data' => $branches->build(
            $request->user(),
            $request->period(),
            $request->reportFilters(),
            $request->branchScope(),
        )]);
    }

    public function parLoans(ParLoansRequest $request, ParLoans $parLoans): JsonResponse
    {
        $scope = LoanScope::fromFilters($request->validated());
        $result = $parLoans->page(
            $request->par(),
            $request->branchScope(),
            $scope,
            (int) ($request->validated('page') ?? 1),
            (int) ($request->validated('per_page') ?? ParLoansRequest::DEFAULT_PER_PAGE),
        );
        $page = $result['page'];

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'par' => $request->par(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'totals' => $result['totals'],
                'filters_applied' => $scope->applied(['product_id', 'officer_id'])['filters_applied'],
            ],
        ]);
    }
}
