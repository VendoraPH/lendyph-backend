<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\ExecutiveBooksRequest;
use App\Models\DashboardSetting;
use App\Services\Dashboard\AlertRules;
use App\Services\Dashboard\ApplicationTurnaround;
use App\Services\Dashboard\DashboardPeriod;
use App\Services\Dashboard\ExecutiveTrends;
use App\Services\Dashboard\LoanScope;
use App\Services\Dashboard\ShareCapitalControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The executive dashboard's books, trends and alerts (Phase 6 EB-D2), under
 * `/dashboard/executive/`. Every request needs `dashboard:analytics`
 * (ExecutiveBooksRequest); each endpoint also needs its sources'
 * permissions, checked here. Figures come from the reports' and the books'
 * own services; nothing here adds them up.
 */
class ExecutiveBooksController extends Controller
{
    /** The alert figures each dashboard filter narrows (AlertRules). */
    private const ATTENTION_FILTERS_APPLIED_TO = [
        'product_id' => ['par30_rise', 'collection_efficiency', 'overdue_share', 'approval_wait', 'release_wait'],
        'officer_id' => ['collection_efficiency', 'overdue_share', 'approval_wait', 'release_wait'],
    ];

    /**
     * Chart B, I and L by month: `months[]` of principal released and
     * collected, cash in, out and net, and new members, each the Cash Flow
     * report's or the Member Report's own figure for that month. Pesos as the
     * reports send them. Needs `reports:view`.
     */
    public function trends(ExecutiveBooksRequest $request, ExecutiveTrends $trends): JsonResponse
    {
        $this->authorize('reports:view');
        $period = $request->period();

        try {
            $months = $trends->months($period, $request->reportFilters());
        } catch (InvalidArgumentException $refusal) {
            throw ValidationException::withMessages(['preset' => [$refusal->getMessage()]]);
        }

        return response()->json(['data' => [
            'period' => $period->toArray(),
            'months' => $months,
            ...LoanScope::fromFilters($request->validated())->applied([], ExecutiveTrends::FILTERS_APPLIED_TO),
        ]]);
    }

    /**
     * The alerts: every rule (AlertRules) in the settings' order with its
     * state, figure, threshold and wording, and `alerts`, the rules that need
     * attention. A rule whose source the caller can't read is `no_access`.
     */
    public function attention(ExecutiveBooksRequest $request, AlertRules $alerts): JsonResponse
    {
        $period = $request->period();
        $rules = $alerts->evaluate($request->user(), $period, $request->reportFilters(), $request->branchScope());

        return response()->json(['data' => [
            'period' => $period->toArray(),
            'as_of' => $period->asOf->toDateString(),
            'thresholds' => DashboardSetting::current()->thresholds(),
            'rules' => $rules,
            'alerts' => array_values(array_filter($rules, fn (array $rule): bool => $rule['state'] === AlertRules::NEEDS_ATTENTION)),
            ...LoanScope::fromFilters($request->validated())->applied([], self::ATTENTION_FILTERS_APPLIED_TO),
        ]]);
    }

    /**
     * Application turnaround (ask 5): submitted → approved, approved →
     * released and submitted → released, each with `loans`, `average_days`,
     * `median_days` (one decimal; null with none) and `not_measured`. Needs
     * `reports:view`.
     */
    public function turnaround(ExecutiveBooksRequest $request, ApplicationTurnaround $turnaround): JsonResponse
    {
        $this->authorize('reports:view');
        $period = $request->period();
        $scope = LoanScope::fromFilters($request->validated());

        return response()->json(['data' => [
            'period' => $period->toArray(),
            ...$turnaround->build($period->from->toDateString(), $period->to->toDateString(), $request->branchScope(), $scope),
            ...$scope->applied(ApplicationTurnaround::FILTERS),
        ]]);
    }

    /**
     * The share capital subledger against the share capital account, by
     * branch, as of `as_of` (today by default). Integer centavos. Needs
     * `accounting:view` and `share_capital:view`.
     */
    public function shareCapitalControl(ExecutiveBooksRequest $request, ShareCapitalControl $control): JsonResponse
    {
        $this->authorize('accounting:view');
        $this->authorize('share_capital:view');

        $asOf = $request->validated('as_of') ?? DashboardPeriod::today()->toDateString();

        return response()->json(['data' => $control->build($asOf, $request->branchScope())]);
    }
}
