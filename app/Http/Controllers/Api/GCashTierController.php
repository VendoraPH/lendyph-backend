<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GCash\UpdateGCashTiersRequest;
use App\Http\Resources\GCashTierResource;
use App\Models\GCashTier;
use App\Services\GCashService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class GCashTierController extends Controller
{
    public function __construct(private GCashService $gcash) {}

    #[OA\Get(
        path: '/api/gcash/tiers',
        summary: 'List GCash tier rates',
        tags: ['GCash'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Tier rows ordered by display_order'),
            new OA\Response(response: 403, description: 'Missing gcash:view permission'),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('gcash:view');

        return GCashTierResource::collection(
            GCashTier::orderBy('display_order')->get(),
        );
    }

    #[OA\Put(
        path: '/api/gcash/tiers',
        summary: 'Replace the full GCash tier table',
        description: 'Wipes existing tiers and writes the provided array. Historical gcash_transactions are not touched — their charge_amount is frozen at create time.',
        tags: ['GCash'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['tiers'],
                properties: [
                    new OA\Property(
                        property: 'tiers',
                        type: 'array',
                        items: new OA\Items(
                            required: ['min_amount', 'max_amount', 'cash_in_rate', 'cash_out_rate', 'display_order'],
                            properties: [
                                new OA\Property(property: 'min_amount', type: 'number'),
                                new OA\Property(property: 'max_amount', type: 'number'),
                                new OA\Property(property: 'cash_in_rate', type: 'number'),
                                new OA\Property(property: 'cash_out_rate', type: 'number'),
                                new OA\Property(property: 'display_order', type: 'integer'),
                            ],
                        ),
                    ),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Tiers replaced'),
            new OA\Response(response: 403, description: 'Missing gcash:settings permission'),
            new OA\Response(response: 422, description: 'Validation error or overlapping ranges'),
        ],
    )]
    public function replace(UpdateGCashTiersRequest $request): AnonymousResourceCollection
    {
        $tiers = $this->gcash->replaceTiers($request->validated()['tiers']);

        return GCashTierResource::collection($tiers);
    }
}
