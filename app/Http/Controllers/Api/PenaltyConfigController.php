<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PenaltyConfig\ListPenaltyConfigsRequest;
use App\Http\Requests\PenaltyConfig\StorePenaltyConfigRequest;
use App\Http\Requests\PenaltyConfig\UpdatePenaltyConfigRequest;
use App\Http\Requests\PenaltyConfig\UpdatePenaltyConfigStatusRequest;
use App\Http\Resources\PenaltyConfigResource;
use App\Models\PenaltyConfig;
use App\Services\LikePattern;
use App\Services\Penalties\PenaltyConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * Settings → Penalties: named sets of late-penalty brackets covering loan
 * products. A loan copies its product's active configuration when it is
 * created, so changes here apply to loans created afterwards only.
 */
class PenaltyConfigController extends Controller
{
    public function __construct(private readonly PenaltyConfigService $service) {}

    #[OA\Get(
        path: '/api/penalty-configs',
        summary: 'List penalty configurations',
        tags: ['Penalties'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100, default: 15)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['active', 'inactive'])),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Part of the name.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated configurations, by name', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PenaltyConfig')),
            ])),
            new OA\Response(response: 403, description: 'Needs penalties:view'),
            new OA\Response(response: 422, description: 'Invalid filter'),
        ],
    )]
    public function index(ListPenaltyConfigsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $search = $filters['search'] ?? null;

        $configs = PenaltyConfig::query()
            ->with(['conditions', 'products:id,name'])
            ->withCount('loans')
            ->when(filled($filters['status'] ?? null), fn ($q) => $q->where('status', $filters['status']))
            ->when(filled($search), fn ($q) => $q->where('name', 'like', LikePattern::contains((string) $search)))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 15));

        return PenaltyConfigResource::collection($configs);
    }

    #[OA\Get(
        path: '/api/penalty-configs/{id}',
        summary: 'Show a penalty configuration',
        tags: ['Penalties'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The configuration', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/PenaltyConfig'),
            ])),
            new OA\Response(response: 403, description: 'Needs penalties:view'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(PenaltyConfig $penaltyConfig): PenaltyConfigResource
    {
        $this->authorize('penalties:view');

        return new PenaltyConfigResource($penaltyConfig->loadCount('loans'));
    }

    #[OA\Post(
        path: '/api/penalty-configs',
        summary: 'Create a penalty configuration',
        description: 'Applies to loans created afterwards on its products. A product can be in only one active configuration.',
        tags: ['Penalties'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PenaltyConfigInput')),
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/PenaltyConfig'),
            ])),
            new OA\Response(response: 403, description: 'Needs penalties:create'),
            new OA\Response(response: 409, description: 'Another save collided with this one'),
            new OA\Response(response: 422, description: 'Validation error in words, including a product already in another active configuration'),
        ],
    )]
    public function store(StorePenaltyConfigRequest $request): JsonResponse
    {
        $config = $this->service->create($request->validated());

        return (new PenaltyConfigResource($config->loadCount('loans')))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Put(
        path: '/api/penalty-configs/{id}',
        summary: 'Update a penalty configuration',
        description: 'Loans already created keep the brackets they were created with.',
        tags: ['Penalties'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PenaltyConfigInput')),
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/PenaltyConfig'),
            ])),
            new OA\Response(response: 403, description: 'Needs penalties:update'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 409, description: 'Another save collided with this one'),
            new OA\Response(response: 422, description: 'Validation error in words'),
        ],
    )]
    public function update(UpdatePenaltyConfigRequest $request, PenaltyConfig $penaltyConfig): PenaltyConfigResource
    {
        $config = $this->service->update($penaltyConfig, $request->validated());

        return new PenaltyConfigResource($config->loadCount('loans'));
    }

    #[OA\Patch(
        path: '/api/penalty-configs/{id}/status',
        summary: 'Activate or deactivate a penalty configuration',
        description: 'Deactivating keeps every loan created under it unchanged; new loans on its products then use the product\'s own rate.',
        tags: ['Penalties'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['status'], properties: [
            new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive']),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/PenaltyConfig'),
            ])),
            new OA\Response(response: 403, description: 'Needs penalties:update'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 409, description: 'Another save collided with this one'),
            new OA\Response(response: 422, description: 'Activating with no products, or with a product already in another active configuration'),
        ],
    )]
    public function updateStatus(UpdatePenaltyConfigStatusRequest $request, PenaltyConfig $penaltyConfig): PenaltyConfigResource
    {
        $config = $this->service->setStatus($penaltyConfig, $request->validated('status'));

        return new PenaltyConfigResource($config->loadCount('loans'));
    }

    #[OA\Delete(
        path: '/api/penalty-configs/{id}',
        summary: 'Delete a penalty configuration',
        tags: ['Penalties'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Deleted'),
            new OA\Response(response: 403, description: 'Needs penalties:delete'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 409, description: 'A loan was created under it: deactivate it instead'),
        ],
    )]
    public function destroy(PenaltyConfig $penaltyConfig): JsonResponse
    {
        $this->authorize('penalties:delete');

        $this->service->delete($penaltyConfig);

        return response()->json(['message' => 'Penalty configuration deleted.']);
    }
}
