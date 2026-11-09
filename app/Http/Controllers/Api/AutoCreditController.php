<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShareCapital\ProcessAutoCreditRequest;
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
        $this->authorize('share_capital:view');

        // Only the caller's branches' members (BranchAccess; all with view_all).
        return response()->json(['data' => $this->shareCapitalService->getAutoCreditStatus(request()->user())]);
    }

    #[OA\Post(
        path: '/api/auto-credit/process',
        summary: 'Execute auto-credit for all eligible members',
        tags: ['Share Capital'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'method', type: 'string', enum: ['cash', 'gcash', 'maya', 'bank_transfer'], default: 'bank_transfer', description: 'The settlement account the credits came through.'),
                    new OA\Property(property: 'reference_number', type: 'string', maxLength: 100, nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Auto-credit processed, and each member\'s credit posted Dr the method\'s account / Cr 3060 Share Capital when the organisation keeps books'),
            new OA\Response(response: 422, description: 'A method outside the list, or a reference over 100 characters'),
            new OA\Response(response: 409, description: 'Collided with another save taking the next reference. Not tried again, so overlapping runs cannot credit a pledge twice. Nothing was posted; check the last run before running again.'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function process(ProcessAutoCreditRequest $request): JsonResponse
    {
        // Credits only the caller's branches' members (all with view_all).
        $run = $this->shareCapitalService->processAutoCredit(
            $request->user(),
            $request->user(),
            $request->input('method'),
            $request->reference_number,
        );

        return response()->json([
            'message' => "Auto-credit processed for {$run->member_count} members. Total: ₱".number_format((float) $run->total_amount, 2).'.',
            'data' => [
                'id' => $run->id,
                'total_amount' => (float) $run->total_amount,
                'member_count' => $run->member_count,
                // Frontend-canonical alias
                'processed_count' => $run->member_count,
                'processed_at' => $run->processed_at?->toDateTimeString(),
                'status' => $run->status,
            ],
        ], 201);
    }
}
