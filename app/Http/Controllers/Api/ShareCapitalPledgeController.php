<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShareCapital\ShareCapitalBulkEntryRequest;
use App\Http\Requests\ShareCapital\ShareCapitalManualEntryRequest;
use App\Http\Requests\ShareCapital\UpdateShareCapitalPledgeRequest;
use App\Http\Resources\ShareCapitalLedgerResource;
use App\Http\Resources\ShareCapitalPledgeResource;
use App\Models\ShareCapitalPledge;
use App\Services\ShareCapitalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class ShareCapitalPledgeController extends Controller
{
    public function __construct(private ShareCapitalService $shareCapitalService) {}

    #[OA\Get(
        path: '/api/pledges',
        summary: 'List share capital pledges',
        tags: ['Share Capital'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'schedule', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'auto_credit', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [new OA\Response(response: 200, description: 'Pledge list')],
    )]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('share_capital:view');

        $pledges = ShareCapitalPledge::with('borrower')
            ->when(request('schedule'), fn ($q, $s) => $q->where('schedule', $s))
            ->when(request()->has('auto_credit'), fn ($q) => $q->where('auto_credit', filter_var(request('auto_credit'), FILTER_VALIDATE_BOOLEAN)))
            ->when(request('search'), fn ($q, $search) => $q->whereHas('borrower', fn ($bq) => $bq->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")))
            ->orderBy('id')
            ->paginate(request('per_page', 15));

        return ShareCapitalPledgeResource::collection($pledges);
    }

    #[OA\Put(
        path: '/api/pledges/{pledge}',
        summary: 'Update pledge amount and schedule',
        tags: ['Share Capital'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'pledge', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent),
        responses: [new OA\Response(response: 200, description: 'Pledge updated')],
    )]
    public function update(UpdateShareCapitalPledgeRequest $request, ShareCapitalPledge $pledge): ShareCapitalPledgeResource
    {
        $pledge->update($request->validated());
        $pledge->load('borrower');

        return new ShareCapitalPledgeResource($pledge);
    }

    #[OA\Patch(
        path: '/api/pledges/{pledge}/auto-credit',
        summary: 'Toggle auto-credit status',
        tags: ['Share Capital'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'pledge', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'Auto-credit toggled')],
    )]
    public function toggleAutoCredit(ShareCapitalPledge $pledge): JsonResponse
    {
        $this->authorize('share_capital:update');

        $pledge->update(['auto_credit' => ! $pledge->auto_credit]);

        return response()->json([
            'message' => 'Auto-credit '.($pledge->auto_credit ? 'enabled' : 'disabled').'.',
            'auto_credit' => $pledge->auto_credit,
        ]);
    }

    #[OA\Post(
        path: '/api/pledges/{pledge}/entries',
        summary: 'Create manual ledger entry for a pledge',
        tags: ['Share Capital'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'pledge', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent),
        responses: [new OA\Response(response: 201, description: 'Entry created')],
    )]
    public function manualEntry(ShareCapitalManualEntryRequest $request, ShareCapitalPledge $pledge): JsonResponse
    {
        $entry = $this->shareCapitalService->createManualEntry(
            $pledge,
            (float) $request->amount,
            $request->type,
            $request->date,
            $request->user(),
            $request->description,
        );

        $entry->load('borrower', 'createdByUser');

        return (new ShareCapitalLedgerResource($entry))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Post(
        path: '/api/pledges/bulk-entries',
        summary: 'Create bulk manual ledger entries',
        tags: ['Share Capital'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent),
        responses: [new OA\Response(response: 201, description: 'Bulk entries created')],
    )]
    public function bulkEntry(ShareCapitalBulkEntryRequest $request): JsonResponse
    {
        $entries = $this->shareCapitalService->bulkManualEntries(
            $request->entries,
            $request->user(),
        );

        return response()->json([
            'message' => "Created {$entries->count()} ledger entries.",
            'count' => $entries->count(),
        ], 201);
    }
}
