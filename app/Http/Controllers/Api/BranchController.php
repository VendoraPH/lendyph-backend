<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\StoreBranchRequest;
use App\Http\Requests\Branch\UpdateBranchRequest;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class BranchController extends Controller
{
    #[OA\Get(
        path: '/api/branches',
        summary: 'List branches',
        description: 'Get all branches',
        tags: ['Branches'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'active_only', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Branch list'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function index(): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $branches = Branch::withCount('users')
            ->when(request()->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();

        return BranchResource::collection($branches);
    }

    #[OA\Post(
        path: '/api/branches',
        summary: 'Create branch',
        description: 'Create a new branch',
        tags: ['Branches'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'code'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Butuan Branch'),
                    new OA\Property(property: 'code', type: 'string', example: 'BTN'),
                    new OA\Property(property: 'address', type: 'string', example: '123 Main St, Butuan City'),
                    new OA\Property(property: 'contact_number', type: 'string', example: '09171234567'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Branch created'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(StoreBranchRequest $request): JsonResponse
    {
        $branch = Branch::create($request->validated());

        return (new BranchResource($branch))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/branches/{id}',
        summary: 'Show branch',
        description: 'Get a specific branch',
        tags: ['Branches'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Branch details'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(Branch $branch): BranchResource
    {
        $branch->loadCount('users');

        return new BranchResource($branch);
    }

    #[OA\Put(
        path: '/api/branches/{id}',
        summary: 'Update branch',
        description: 'Update a branch',
        tags: ['Branches'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'code', type: 'string'),
                    new OA\Property(property: 'address', type: 'string'),
                    new OA\Property(property: 'contact_number', type: 'string'),
                    new OA\Property(property: 'is_active', type: 'boolean'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Branch updated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function update(UpdateBranchRequest $request, Branch $branch): BranchResource
    {
        $branch->update($request->validated());

        return new BranchResource($branch);
    }
}
