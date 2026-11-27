<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\DashboardHistoryRequest;
use App\Services\Dashboard\LoanScope;
use App\Services\Dashboard\PortfolioHistory;
use Illuminate\Http\JsonResponse;

/**
 * The executive dashboard's portfolio history (Phase 6 EB-B): month-end
 * points or one date, from the daily snapshots, in the caller's branches.
 */
class DashboardHistoryController extends Controller
{
    /** The filters history follows (2026-11-17 decision 17). */
    private const HONOURED_FILTERS = ['branch_id', 'product_id'];

    public function __construct(private PortfolioHistory $history) {}

    public function index(DashboardHistoryRequest $request): JsonResponse
    {
        $productId = $request->validated('product_id');

        return response()->json(['data' => [
            ...$this->history->points(
                $request->requestedDates(),
                $request->branchScope(),
                filled($productId) ? (int) $productId : null,
            ),
            ...LoanScope::fromFilters($request->validated())->applied(self::HONOURED_FILTERS),
        ]]);
    }
}
