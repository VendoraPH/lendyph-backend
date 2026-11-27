<?php

namespace App\Http\Controllers\Api\LoanOrigination\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoanOrigination\Staff\OriginationDashboardRequest;
use App\Services\LoanOrigination\Staff\OriginationDashboard;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * The loan origination dashboard (Phase 6, LO-B3): KPIs, the period before,
 * trends, breakdowns, stage times and turnaround, all from the server
 * (OriginationDashboard), for the caller's branches.
 */
class DashboardController extends Controller
{
    #[OA\Get(
        path: '/api/loan-origination/dashboard',
        summary: 'Online applications dashboard',
        description: 'date_from/date_to (Manila days; this month to today by default; at most a year) and branch_id (inside the caller\'s branches, 403 otherwise). Test links are left out. Money is a two-decimal string; rates and hours one decimal, null with nothing to measure. `previous` holds the KPIs for the period of the same length just before, and `changes` each KPI\'s change from it, worked out on the server by the executive dashboard\'s rule: `{change, change_unit}`, a percentage of the previous figure (`percent`) or, for completion_rate and approval_rate, points (`points`), two decimals; `change` is null when either figure is missing or withheld (amount_approved without loans:view) or the previous figure is zero. `turnaround` is the executive dashboard\'s ApplicationTurnaround on the loans the applications became.',
        tags: ['Loan Origination'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'The dashboard'),
            new OA\Response(response: 403, description: 'Missing loan_origination:view, or a branch outside the caller\'s'),
            new OA\Response(response: 422, description: 'A bad or too long period'),
        ],
    )]
    public function show(OriginationDashboardRequest $request, OriginationDashboard $dashboard): JsonResponse
    {
        [$from, $to] = $request->period();

        return response()->json(['success' => true, 'data' => $dashboard->build($from, $to, $request->branchScope(), $request->user())]);
    }
}
