<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShareCapital\ProcessAutoCreditRequest;
use App\Models\AutoCreditRunSkip;
use App\Services\ShareCapitalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class AutoCreditController extends Controller
{
    public function __construct(private ShareCapitalService $shareCapitalService) {}

    #[OA\Get(
        path: '/api/auto-credit/status',
        summary: 'Get auto-credit status and member breakdown',
        tags: ['Share Capital'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'cycle', in: 'query', required: false, description: 'The half of the month the run would be for: `15` or `30`. Defaults by today in Manila (the 1st–15th gives `15`).', schema: new OA\Schema(type: 'string', enum: ['15', '30'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Auto-credit status: the run\'s preview for `data.cycle`, with the pledges it would skip and why.'),
            new OA\Response(response: 422, description: 'A cycle other than 15 or 30, in words'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function status(Request $request): JsonResponse
    {
        $this->authorize('share_capital:view');

        $data = $request->validate(['cycle' => ProcessAutoCreditRequest::cycleRules(required: false)], ProcessAutoCreditRequest::cycleMessages());

        // Only the caller's branches' members (BranchAccess; all with view_all).
        return response()->json(['data' => $this->shareCapitalService->getAutoCreditStatus($request->user(), $data['cycle'] ?? null)]);
    }

    #[OA\Post(
        path: '/api/auto-credit/process',
        summary: 'Execute auto-credit for all eligible members',
        tags: ['Share Capital'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['cycle'],
                properties: [
                    new OA\Property(property: 'cycle', type: 'string', enum: ['15', '30'], description: 'The half of the month the run is for (finish run round 8): the 15th run credits "15" and "15/30" pledges, the 30th run "30" and "15/30".'),
                    new OA\Property(property: 'method', type: 'string', enum: ['cash', 'gcash', 'maya', 'bank_transfer'], default: 'bank_transfer', description: 'The settlement account the credits came through.'),
                    new OA\Property(property: 'reference_number', type: 'string', maxLength: 100, nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Auto-credit processed, and each member\'s credit posted Dr the method\'s account / Cr 3060 Share Capital when the organisation keeps books. A pledge the run doesn\'t credit is skipped: its schedule is credited in the other half, a run already credited it in this half this month (Manila), or its month\'s instalments are all in. `data.skipped` lists each with its reason, and `data.skipped_count` counts them. `data.cycle` is the run\'s half.'),
            new OA\Response(response: 422, description: 'No cycle or one other than 15 or 30, a method outside the list, or a reference over 100 characters, in words'),
            new OA\Response(response: 409, description: 'Collided with another save taking the next reference. Not tried again, so overlapping runs cannot credit a pledge twice. Nothing was posted; check the last run before running again.'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function process(ProcessAutoCreditRequest $request): JsonResponse
    {
        // Credits only the caller's branches' members (all with view_all).
        $run = $this->shareCapitalService->processAutoCredit(
            $request->user(),
            $request->input('cycle'),
            $request->user(),
            $request->input('method'),
            $request->reference_number,
        );

        return response()->json([
            'message' => 'Auto-credit for '.ShareCapitalService::cycleWords($run->cycle)." processed for {$run->member_count} members. Total: ₱".number_format((float) $run->total_amount, 2).'.',
            'data' => [
                'id' => $run->id,
                'total_amount' => (float) $run->total_amount,
                'member_count' => $run->member_count,
                // Frontend-canonical alias
                'processed_count' => $run->member_count,
                'processed_at' => $run->processed_at?->toDateTimeString(),
                'status' => $run->status,
                // The half of the month the run was for (finish run round 8).
                'cycle' => $run->cycle,
                // Pledges already credited by hand this month (finish run round 7).
                'skipped_count' => (int) $run->skipped_count,
                'skipped' => $run->skips->map(fn (AutoCreditRunSkip $skip): array => [
                    'pledge_id' => $skip->pledge_id,
                    'borrower_name' => $skip->borrower?->full_name,
                    'amount' => (float) $skip->amount,
                    'reason' => $skip->reason,
                ])->values()->all(),
            ],
        ], 201);
    }
}
