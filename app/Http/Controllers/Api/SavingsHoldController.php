<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Savings\Hold\PlaceSavingsHoldRequest;
use App\Http\Requests\Savings\Hold\ReleaseSavingsHoldRequest;
use App\Models\SavingsHold;
use App\Services\GeneratedNumberWrite;
use App\Services\Savings\Api\AccountScope;
use App\Services\Savings\Api\HoldDesk;
use App\Services\Savings\Api\Presenter;
use App\Services\Savings\IdempotentWrite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/**
 * Holds on savings accounts (design §2, slice S2). An active hold comes off
 * the available balance until it is released or passes `expires_on`.
 * Placing one reserves money, so it is idempotent like a money post; a lock
 * wait answers 409.
 * Another branch's account or hold answers 404.
 */
class SavingsHoldController extends Controller
{
    public function __construct(
        private readonly AccountScope $scope,
        private readonly Presenter $presenter,
    ) {}

    #[OA\Get(
        path: '/api/savings/accounts/{account}/holds',
        summary: 'An account\'s holds',
        description: 'Every hold, active first, then newest first. A short list, so not paged.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'account', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The holds'),
            new OA\Response(response: 403, description: 'Missing savings:view'),
            new OA\Response(response: 404, description: 'No such account in the caller\'s branches'),
        ],
    )]
    public function index(Request $request, int $account): JsonResponse
    {
        $this->authorize('savings:view');
        $found = $this->scope->account($request->user(), $account);

        $holds = SavingsHold::query()->where('account_id', $found->id)
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->get();

        return response()->json(['success' => true, 'data' => $this->presenter->holds($holds)]);
    }

    #[OA\Post(
        path: '/api/savings/accounts/{account}/holds',
        summary: 'Place a hold',
        description: 'A legal order or a staff hold, up to the balance not already held, until `expires_on` (today or later) or until released. Refused (422) on a closed account. It reserves money, so it needs an `Idempotency-Key` header (UUID) like the money posts.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'account', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['type', 'amount', 'reason'], properties: [
            new OA\Property(property: 'type', type: 'string', enum: ['legal', 'manual']),
            new OA\Property(property: 'amount', type: 'string', example: '500.00'),
            new OA\Property(property: 'reason', type: 'string'),
            new OA\Property(property: 'expires_on', type: 'string', format: 'date', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The hold'),
            new OA\Response(response: 403, description: 'Missing savings:transact'),
            new OA\Response(response: 404, description: 'No such account in the caller\'s branches'),
            new OA\Response(response: 409, description: 'Another write got there first'),
            new OA\Response(response: 422, description: 'Above the balance not already held, the account is closed, or no Idempotency-Key'),
        ],
    )]
    public function store(PlaceSavingsHoldRequest $request, HoldDesk $desk, IdempotentWrite $idempotent, int $account): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        return $idempotent->run($request, fn (): JsonResponse => response()->json([
            'success' => true,
            'data' => $this->presenter->hold($desk->place($account, $data, $user)),
        ], 201));
    }

    #[OA\Post(
        path: '/api/savings/holds/{hold}/release',
        summary: 'Release a hold',
        description: 'Once, with a reason. 409 when the hold is no longer active.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'hold', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [
            new OA\Property(property: 'reason', type: 'string'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The released hold'),
            new OA\Response(response: 403, description: 'Missing savings:transact'),
            new OA\Response(response: 404, description: 'No such hold in the caller\'s branches'),
            new OA\Response(response: 409, description: 'Already released or expired, or another write got there first'),
        ],
    )]
    public function release(ReleaseSavingsHoldRequest $request, HoldDesk $desk, int $hold): JsonResponse
    {
        /** @var SavingsHold $found */
        $found = $request->record();
        $reason = $request->validated('reason');
        $released = GeneratedNumberWrite::run(
            fn (): SavingsHold => DB::transaction(fn (): SavingsHold => $desk->release($found, $reason, $request->user())),
            GeneratedNumberWrite::SAVINGS_CONFLICT,
        );

        return response()->json(['success' => true, 'data' => $this->presenter->hold($released)]);
    }
}
