<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ProfitSharingReportRequest;
use App\Services\BranchAccess;
use App\Services\Reports\ProfitSharingReport;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class ProfitSharingReportController extends Controller
{
    #[OA\Get(
        path: '/api/reports/profit-sharing',
        summary: 'Profit Sharing',
        description: 'How the year\'s distributable profit would be shared among the members by their share capital on `cutoff_date`. A report only: nothing is credited or posted. '
            .'`profit` (pesos) runs from 1 January of the cutoff date\'s year (`period_from`) to the cutoff date: the Income report\'s `interest_income`, `penalty_income`, `processing_fees`, `service_fee` and `other_fees` for that period (`lending_fees` the three fees, `lending_income` all five), less `expenses` (the expenses recorded in Expenses, dated in the period), less `losses`: `allowance_for_credit_losses` (allowance postings provided less released) plus `principal_written_off` (by restructures released in the period) less `written_off_covered` (what the allowance or unearned interest covered, as each write-off journal posts it; nothing without books). '
            .'A journal reversed on or before the cutoff date counts as never posted. `distributable_profit` is `lending_income` less `expenses` less `losses`. '
            .'`rows`: each member holding share capital on the cutoff date (the ledger\'s balance to that date), by name: `member_code`, `member_name`, `share_capital`, `share_percent` (of all members\' share capital, to four places), `earnings` ((share capital ÷ all members\' share capital) × distributable profit, exact, half up to the centavo; 0 when `distribution` isn\'t `shared`) and `capital_plus_earnings`. '
            .'`distribution` is `shared`, `no_profit` (profit at or below 0) or `no_share_capital`. `totals` are the server\'s: `member_count`, `share_capital`, `share_percent` (100, or null with no members), `earnings`, `capital_plus_earnings`, `rounding_remainder` (profit less earnings, never spread) and `members_with_negative_balance` (left out). '
            .'Needs reports:view and share_capital:view (403 in words). `rows` also needs reports:export: without it `rows` is null and `rows_omitted` says why, while every figure and total is unchanged. '
            .'Branches follow the reports\' rule (`branch_scope` `organisation` or `branches`): a branch\'s own income, expenses and losses, shared among the members now in it. `basis_note` marks the definition as one to confirm with the owner.',
        tags: ['Reports'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'cutoff_date', in: 'query', required: false, description: 'Year-month-day, no later than today; today when left out.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, description: 'One of the caller\'s branches.', schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The profit, how it was reached, and each member\'s share'),
            new OA\Response(response: 403, description: 'Missing reports:view or share_capital:view, or a branch outside the caller\'s'),
            new OA\Response(response: 422, description: 'An unreadable cutoff date, or one after today'),
        ],
    )]
    public function __invoke(ProfitSharingReportRequest $request, BranchAccess $access, ProfitSharingReport $report): JsonResponse
    {
        $user = $request->user();
        $branchId = $request->validated('branch_id');
        $access->authorizeBranchFilter($user, $branchId);

        return response()->json(['data' => $report->build(
            cutoff: $request->validated('cutoff_date') ?? today()->toDateString(),
            branchId: $branchId === null ? null : (int) $branchId,
            callerBranches: $access->for($user),
            // The member rows are the membership's holdings: like the Share
            // Capital report's roster, they need `reports:export`.
            withRows: $user->can('reports:export'),
        )]);
    }
}
