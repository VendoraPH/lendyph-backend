<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class RoleController extends Controller
{
    #[OA\Get(
        path: '/api/roles',
        summary: 'List roles',
        description: 'Returns all roles with their permissions and a count of assigned users. Includes `is_system` and `is_active` flags.',
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
            Role::with('permissions')
                ->withCount('users')
                ->orderBy('is_system', 'desc')
                ->orderBy('name')
                ->get()
        );
    }

    #[OA\Get(
        path: '/api/roles/{id}',
        summary: 'Show role',
        description: 'Get a specific role with permissions and user count',
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

        $role->load('permissions')->loadCount('users');

        return new RoleResource($role);
    }

    #[OA\Post(
        path: '/api/roles',
        summary: 'Create custom role',
        description: 'Creates a new non-system role. Name must be snake_case and unique. Permissions are an array of permission names.',
        tags: ['Roles'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'branch_manager'),
                    new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Manages a single branch'),
                    new OA\Property(
                        property: 'permissions',
                        type: 'array',
                        items: new OA\Items(type: 'string'),
                        example: ['dashboard:view', 'borrowers:view', 'loans:view'],
                    ),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Role created'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(StoreRoleRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $role = DB::transaction(function () use ($validated) {
            $role = Role::create([
                'name' => $validated['name'],
                'guard_name' => 'web',
                'description' => $validated['description'] ?? null,
                'is_active' => true,
                'is_system' => false,
            ]);

            if (! empty($validated['permissions'])) {
                $role->syncPermissions($validated['permissions']);
            }

            return $role;
        });

        $role->load('permissions')->loadCount('users');

        return (new RoleResource($role))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Put(
        path: '/api/roles/{id}',
        summary: 'Update role',
        description: 'Update description, permissions, or name. System roles cannot be renamed.',
        tags: ['Roles'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', description: 'Only editable on custom roles'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string')),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Role updated'),
            new OA\Response(response: 422, description: 'Validation error or system-role rename attempted'),
        ],
    )]
    public function update(UpdateRoleRequest $request, Role $role): RoleResource
    {
        $validated = $request->validated();

        DB::transaction(function () use ($role, $validated) {
            $fields = collect($validated)->only(['name', 'description'])->toArray();
            if ($fields !== []) {
                $role->update($fields);
            }

            if (array_key_exists('permissions', $validated)) {
                $role->syncPermissions($validated['permissions'] ?? []);
            }
        });

        $role->load('permissions')->loadCount('users');

        return new RoleResource($role);
    }

    #[OA\Patch(
        path: '/api/roles/{id}/deactivate',
        summary: 'Deactivate role',
        description: 'Marks the role as inactive. The admin role cannot be deactivated.',
        tags: ['Roles'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Role deactivated'),
            new OA\Response(response: 422, description: 'Cannot deactivate admin role'),
        ],
    )]
    public function deactivate(Role $role): RoleResource
    {
        $this->authorize('settings:update');

        if ($role->name === 'admin') {
            throw ValidationException::withMessages([
                'role' => 'The admin role cannot be deactivated.',
            ]);
        }

        $role->update(['is_active' => false]);
        $role->load('permissions')->loadCount('users');

        return new RoleResource($role);
    }

    #[OA\Patch(
        path: '/api/roles/{id}/reactivate',
        summary: 'Reactivate role',
        tags: ['Roles'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Role reactivated'),
        ],
    )]
    public function reactivate(Role $role): RoleResource
    {
        $this->authorize('settings:update');

        $role->update(['is_active' => true]);
        $role->load('permissions')->loadCount('users');

        return new RoleResource($role);
    }

    #[OA\Delete(
        path: '/api/roles/{id}',
        summary: 'Delete custom role',
        description: 'Deletes a custom (non-system) role. Rejects deletion if the role is assigned to any users — reassign users first.',
        tags: ['Roles'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Role deleted'),
            new OA\Response(response: 422, description: 'System role or role in use'),
        ],
    )]
    public function destroy(Role $role): JsonResponse
    {
        $this->authorize('settings:delete');

        if ($role->is_system) {
            throw ValidationException::withMessages([
                'role' => "System role '{$role->name}' cannot be deleted.",
            ]);
        }

        $usersCount = $role->users()->count();
        if ($usersCount > 0) {
            throw ValidationException::withMessages([
                'role' => "Role '{$role->name}' is assigned to {$usersCount} user(s). Reassign them before deleting.",
            ]);
        }

        $role->delete();

        return response()->json(['message' => 'Role deleted successfully.']);
    }
}
