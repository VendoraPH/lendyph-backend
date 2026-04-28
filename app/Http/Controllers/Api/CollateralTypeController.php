<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CollateralType\StoreCollateralTypeRequest;
use App\Http\Requests\CollateralType\UpdateCollateralTypeRequest;
use App\Http\Resources\CollateralTypeResource;
use App\Models\CollateralType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class CollateralTypeController extends Controller
{
    #[OA\Get(
        path: '/api/collateral-types',
        summary: 'List collateral types',
        description: 'Returns all configurable collateral types ordered by display_order then name.',
        tags: ['Collateral Types'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Collateral type list'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('collaterals:view');

        return CollateralTypeResource::collection(
            CollateralType::orderBy('display_order')->orderBy('name')->get()
        );
    }

    #[OA\Post(
        path: '/api/collateral-types',
        summary: 'Create collateral type',
        tags: ['Collateral Types'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'detail_field_label', 'amount_field_label'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Vehicle Title'),
                    new OA\Property(property: 'detail_field_label', type: 'string', example: 'OR/CR No.'),
                    new OA\Property(property: 'amount_field_label', type: 'string', example: 'Appraised Value'),
                    new OA\Property(property: 'source', type: 'string', enum: ['manual', 'share_capital'], example: 'manual'),
                    new OA\Property(property: 'display_order', type: 'integer', example: 5),
                    new OA\Property(property: 'is_visible', type: 'boolean', example: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Collateral type created'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(StoreCollateralTypeRequest $request): JsonResponse
    {
        $type = CollateralType::create(array_merge(
            ['source' => 'manual', 'display_order' => 0, 'is_visible' => true, 'is_seed' => false],
            $request->validated(),
        ));

        return (new CollateralTypeResource($type))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/collateral-types/{id}',
        summary: 'Show collateral type',
        tags: ['Collateral Types'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Collateral type details'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(CollateralType $collateralType): CollateralTypeResource
    {
        $this->authorize('collaterals:view');

        return new CollateralTypeResource($collateralType);
    }

    #[OA\Put(
        path: '/api/collateral-types/{id}',
        summary: 'Update collateral type',
        tags: ['Collateral Types'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent),
        responses: [
            new OA\Response(response: 200, description: 'Collateral type updated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function update(UpdateCollateralTypeRequest $request, CollateralType $collateralType): CollateralTypeResource
    {
        $collateralType->update($request->validated());

        return new CollateralTypeResource($collateralType);
    }

    #[OA\Delete(
        path: '/api/collateral-types/{id}',
        summary: 'Delete collateral type',
        description: 'Rejects deletion when the type is seeded (`is_seed=true`) or has any collaterals referencing it.',
        tags: ['Collateral Types'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Collateral type deleted'),
            new OA\Response(response: 422, description: 'Cannot delete seed/in-use type'),
        ],
    )]
    public function destroy(CollateralType $collateralType): JsonResponse
    {
        $this->authorize('settings:delete');

        if ($collateralType->is_seed) {
            throw ValidationException::withMessages([
                'collateral_type' => "Seed collateral type '{$collateralType->name}' cannot be deleted.",
            ]);
        }

        $count = $collateralType->collaterals()->count();
        if ($count > 0) {
            throw ValidationException::withMessages([
                'collateral_type' => "Type '{$collateralType->name}' is used by {$count} collateral(s).",
            ]);
        }

        $collateralType->delete();

        return response()->json(['message' => 'Collateral type deleted successfully.']);
    }
}
