<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class DashboardController extends Controller
{
    public function __construct(private DashboardService $dashboardService) {}

    #[OA\Get(
        path: '/api/dashboard/stats',
        summary: 'Dashboard KPI stats',
        tags: ['Dashboard'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Dashboard KPI metrics with sparklines'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function stats(): JsonResponse
    {
        $this->authorize('dashboard.view');

        return response()->json(['data' => $this->dashboardService->stats()]);
    }

    #[OA\Get(
        path: '/api/dashboard/collections-trend',
        summary: 'Collections trend (last 12 weeks)',
        tags: ['Dashboard'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Weekly collections trend'),
        ],
    )]
    public function collectionsTrend(): JsonResponse
    {
        $this->authorize('dashboard.view');

        return response()->json($this->dashboardService->collectionsTrend());
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
            new OA\Response(response: 200, description: 'Daily dues list with summary'),
        ],
    )]
    public function dailyDues(): JsonResponse
    {
        $this->authorize('dashboard.view');

        return response()->json($this->dashboardService->dailyDues(request('date')));
    }

    #[OA\Get(
        path: '/api/dashboard/recent-transactions',
        summary: 'Recent transactions (payments + releases)',
        tags: ['Dashboard'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Recent transaction activity feed'),
        ],
    )]
    public function recentTransactions(): JsonResponse
    {
        $this->authorize('dashboard.view');

        return response()->json($this->dashboardService->recentTransactions());
    }
}
