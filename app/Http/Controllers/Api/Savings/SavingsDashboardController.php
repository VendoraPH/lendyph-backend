<?php

namespace App\Http\Controllers\Api\Savings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Savings\Dashboard\SavingsDashboardRequest;
use App\Services\Savings\Dashboard\SavingsDashboard;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * The Savings dashboard (gap round S5, the Savings task's TASK 35): KPIs, a
 * monthly series, breakdowns by product and by branch, time deposit maturity
 * buckets and the GL control, every figure from the server, in the caller's
 * branches.
 */
class SavingsDashboardController extends Controller
{
    public function __construct(private readonly SavingsDashboard $dashboard) {}

    #[OA\Get(
        path: '/api/savings/dashboard',
        summary: 'Savings dashboard',
        description: 'Money as two-decimal strings. `kpis`: total_deposits (savings balance plus time deposits outstanding at `date_to`), savings_balance, time_deposits {count, amount}, accounts {active, dormant, frozen, as_of} by today\'s status, new_accounts, deposits and withdrawals {count, amount} in the period (reversed rows and moves to or from time deposits left out; withdrawals include closing payouts), net_deposit_movement, interest_credited {gross, tax, net}, fees_charged {count, amount} (excess-withdrawal fees, income, never a withdrawal), share_capital_transfers {count, amount} (savings moved to share capital, never a withdrawal), time_deposit_interest_paid {gross, tax}, maturing_30_days {count, principal, maturity_amount}, and gl_control {as_of, is_balanced, differences, subledger_balance, gl_balance, difference, warning, product_filter_ignored}. `series`: up to 12 months ending with `date_to`\'s month. `by_product` and `by_branch`: {rows, totals}, the deposit summary\'s figures. `maturity_buckets`: 0–30, 31–60, 61–90 and over 90 days to maturity.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'), description: 'Defaults to the first of `to`\'s month.'),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'), description: 'Defaults to today; not after today.'),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'product_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The dashboard'),
            new OA\Response(response: 403, description: 'Missing savings:view, or a branch outside the caller\'s'),
            new OA\Response(response: 422, description: 'An invalid filter'),
        ],
    )]
    public function __invoke(SavingsDashboardRequest $request): JsonResponse
    {
        $scope = $request->scope();
        [$from, $to] = $request->period();

        return response()->json(['success' => true, 'data' => $this->dashboard->build($scope, $from, $to)]);
    }
}
