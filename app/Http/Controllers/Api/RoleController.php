<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    #[OA\Get(
        path: '/api/roles',
        summary: 'List roles',
        description: 'Get all roles with their permissions',
        tags: ['Roles'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Role list'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('users:view');

        return RoleResource::collection(
            Role::with('permissions')->orderBy('name')->get()
        );
    }

    #[OA\Get(
        path: '/api/roles/{id}',
        summary: 'Show role',
        description: 'Get a specific role with permissions',
        tags: ['Roles'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Role details'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(Role $role): RoleResource
    {
        $this->authorize('users:view');

        $role->load('permissions');

        return new RoleResource($role);
    }
}
