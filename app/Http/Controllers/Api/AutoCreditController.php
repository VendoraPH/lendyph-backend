<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ShareCapitalService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class AutoCreditController extends Controller
{
    public function __construct(private ShareCapitalService $shareCapitalService) {}

    #[OA\Get(
        path: '/api/auto-credit/status',
        summary: 'Get auto-credit status and member breakdown',
        tags: ['Share Capital'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Auto-credit status'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function status(): JsonResponse
    {
        $this->authorize('share-capital.view');

        return response()->json(['data' => $this->shareCapitalService->getAutoCreditStatus()]);
    }

    #[OA\Post(
        path: '/api/auto-credit/process',
        summary: 'Execute auto-credit for all eligible members',
        tags: ['Share Capital'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 201, description: 'Auto-credit processed'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function process(): JsonResponse
    {
        $this->authorize('auto-credit.process');

        $run = $this->shareCapitalService->processAutoCredit(request()->user());

        return response()->json([
            'message' => "Auto-credit processed for {$run->member_count} members. Total: ₱".number_format((float) $run->total_amount, 2).'.',
            'data' => [
                'id' => $run->id,
                'total_amount' => (float) $run->total_amount,
                'member_count' => $run->member_count,
                'processed_at' => $run->processed_at?->toDateTimeString(),
                'status' => $run->status,
            ],
        ], 201);
    }
}
