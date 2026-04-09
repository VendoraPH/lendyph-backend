<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Fee\StoreFeeRequest;
use App\Http\Requests\Fee\UpdateFeeRequest;
use App\Http\Resources\FeeResource;
use App\Models\Fee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class FeeController extends Controller
{
    #[OA\Get(
        path: '/api/fees',
        summary: 'List fees',
        tags: ['Fees'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Fee list'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('fees:view');

        return FeeResource::collection(Fee::orderBy('name')->get());
    }

    #[OA\Post(
        path: '/api/fees',
        summary: 'Create fee',
        tags: ['Fees'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'type', 'value'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Processing Fee'),
                    new OA\Property(property: 'type', type: 'string', enum: ['fixed', 'percentage']),
                    new OA\Property(property: 'value', type: 'number', example: 500),
                    new OA\Property(property: 'applicable_product_ids', type: 'array', items: new OA\Items(type: 'integer')),
                    new OA\Property(property: 'conditions', type: 'object'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Fee created'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(StoreFeeRequest $request): JsonResponse
    {
        $fee = Fee::create($request->validated());

        return (new FeeResource($fee))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/fees/{id}',
        summary: 'Show fee',
        tags: ['Fees'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Fee details'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(Fee $fee): FeeResource
    {
        $this->authorize('fees:view');

        return new FeeResource($fee);
    }

    #[OA\Put(
        path: '/api/fees/{id}',
        summary: 'Update fee',
        tags: ['Fees'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent),
        responses: [
            new OA\Response(response: 200, description: 'Fee updated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function update(UpdateFeeRequest $request, Fee $fee): FeeResource
    {
        $fee->update($request->validated());

        return new FeeResource($fee);
    }

    #[OA\Delete(
        path: '/api/fees/{id}',
        summary: 'Delete fee',
        tags: ['Fees'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Fee deleted'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function destroy(Fee $fee): JsonResponse
    {
        $this->authorize('fees:delete');

        $fee->delete();

        return response()->json(['message' => 'Fee deleted successfully.']);
    }
}
