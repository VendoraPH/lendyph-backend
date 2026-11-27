<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\StoreBranchRequest;
use App\Http\Requests\Branch\UpdateBranchRequest;
use App\Http\Resources\BranchResource;
use App\Http\Resources\PublicBranchResource;
use App\Models\Branch;
use App\Services\BranchAccess;
use App\Services\Branches\BranchFigures;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use OpenApi\Attributes as OA;

class BranchController extends Controller
{
    #[OA\Get(
        path: '/api/branches',
        summary: 'List branches',
        description: 'Every branch for a caller holding branches:view_all; otherwise only the caller\'s own branches.',
        tags: ['Branches'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'active_only', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'include', in: 'query', required: false, description: '`figures` adds each branch\'s figures (null without loans:view)', schema: new OA\Schema(type: 'string', enum: ['figures'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Branch list'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function index(BranchFigures $figures): AnonymousResourceCollection
    {
        // Decision B2: a caller without `branches:view_all` lists only their
        // own branches (none when they have no branch).
        $branchIds = app(BranchAccess::class)->for(request()->user());

        $branches = Branch::withCount('users')
            ->withClosurePending()
            ->with('manager:id,first_name,last_name')
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branches.id', $branchIds))
            ->when(request()->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();

        if (request()->query('include') !== 'figures') {
            return BranchResource::collection($branches);
        }

        $byBranch = request()->user()->can('loans:view')
            ? $figures->for($branches->pluck('id')->map(fn ($id): int => (int) $id)->all())
            : [];

        return BranchResource::collection($branches->map(
            fn (Branch $branch): BranchResource => (new BranchResource($branch))->withFigures($byBranch[$branch->id] ?? null),
        ));
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
        $branch = Branch::create($request->validated())->load('manager:id,first_name,last_name');

        return (new BranchResource($branch))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/branches/{id}',
        summary: 'Show branch',
        description: 'Get a specific branch. A branch outside the caller\'s branches answers 404 unless they hold branches:view_all.',
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
    public function show(Branch $branch, BranchFigures $figures): BranchResource
    {
        $this->denyHiddenBranch($branch);

        $branch->loadCount('users')->loadClosurePending()->load('manager:id,first_name,last_name');

        return (new BranchResource($branch))->withFigures(
            request()->user()->can('loans:view') ? $figures->for([$branch->id])[$branch->id] : null,
        );
    }

    #[OA\Put(
        path: '/api/branches/{id}',
        summary: 'Update branch',
        description: 'Update a branch. A branch outside the caller\'s branches answers 404 (before permission and validation checks) unless they hold branches:view_all.',
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
                    new OA\Property(property: 'email', type: 'string', nullable: true),
                    new OA\Property(property: 'manager_user_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'opened_on', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'cda_authority_number', type: 'string', nullable: true, description: 'To verify'),
                    new OA\Property(property: 'cda_authority_issued_on', type: 'string', format: 'date', nullable: true, description: 'To verify'),
                    new OA\Property(property: 'is_active', type: 'boolean', description: 'Accepted only unchanged; change the status with PATCH /api/branches/{id}/status'),
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
        // `is_active` passes validation only unchanged, so it changes nothing.
        $data = Arr::except($request->validated(), ['is_active']);

        $branch->fill($data)->save();

        return new BranchResource($branch->loadClosurePending()->load('manager:id,first_name,last_name'));
    }

    #[OA\Get(
        path: '/api/branches/public',
        summary: 'Public branch list',
        description: 'Returns a slim list of active branches for the public registration page. No authentication required. Only id, name, and city are exposed — internal codes, financial fields, and manager assignments are filtered out.',
        tags: ['Branches'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Public branch list',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 1),
                                    new OA\Property(property: 'name', type: 'string', example: 'Main Branch'),
                                    new OA\Property(property: 'city', type: 'string', nullable: true, example: 'Iloilo City'),
                                ],
                            ),
                        ),
                    ],
                ),
            ),
        ],
    )]
    public function publicIndex(): AnonymousResourceCollection
    {
        $branches = Branch::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'address']);

        return PublicBranchResource::collection($branches);
    }

    /**
     * Decision B2: a branch the caller can't see answers exactly like a
     * missing one (the route binding's 404 and message), so its existence is
     * not revealed. `branches:view_all` sees every branch.
     */
    private function denyHiddenBranch(Branch $branch): void
    {
        if (! app(BranchAccess::class)->canSee(request()->user(), $branch->id)) {
            throw (new ModelNotFoundException)->setModel(Branch::class, [$branch->id]);
        }
    }
}
