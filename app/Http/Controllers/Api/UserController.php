<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\ResetPasswordRequest;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class UserController extends Controller
{
    #[OA\Get(
        path: '/api/users',
        summary: 'List users',
        description: 'Get a paginated list of all users',
        tags: ['Users'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['active', 'inactive'])),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'role', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated user list'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('users:view');

        $users = User::with('branch', 'roles')
            ->when(request('search'), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when(request('status'), fn ($query, $status) => $query->where('status', $status))
            ->when(request('branch_id'), fn ($query, $branchId) => $query->forBranch($branchId))
            ->when(request('role'), fn ($query, $role) => $query->role($role))
            ->latest()
            ->paginate(request('per_page', 15));

        return UserResource::collection($users);
    }

    #[OA\Post(
        path: '/api/users',
        summary: 'Create user',
        description: 'Create a new user account',
        tags: ['Users'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['first_name', 'last_name', 'username', 'email', 'password', 'password_confirmation', 'branch_id', 'role'],
                properties: [
                    new OA\Property(property: 'first_name', type: 'string', example: 'John'),
                    new OA\Property(property: 'last_name', type: 'string', example: 'Doe'),
                    new OA\Property(property: 'username', type: 'string', example: 'johndoe'),
                    new OA\Property(property: 'email', type: 'string', example: 'john@lendyph.com'),
                    new OA\Property(property: 'mobile_number', type: 'string', example: '09171234567'),
                    new OA\Property(property: 'password', type: 'string', example: 'password123'),
                    new OA\Property(property: 'password_confirmation', type: 'string', example: 'password123'),
                    new OA\Property(property: 'branch_id', type: 'integer', example: 1),
                    new OA\Property(property: 'role', type: 'string', example: 'loan-officer'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'User created'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = User::create($request->safe()->except('role'));
        $user->assignRole($request->role);

        $user->load('branch', 'roles');

        return (new UserResource($user))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/users/{id}',
        summary: 'Show user',
        description: 'Get a specific user by ID',
        tags: ['Users'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'User details'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(User $user): UserResource
    {
        $this->authorize('users:view');

        $user->load('branch', 'roles', 'permissions');

        return new UserResource($user);
    }

    #[OA\Put(
        path: '/api/users/{id}',
        summary: 'Update user',
        description: 'Update an existing user',
        tags: ['Users'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'first_name', type: 'string'),
                    new OA\Property(property: 'last_name', type: 'string'),
                    new OA\Property(property: 'username', type: 'string'),
                    new OA\Property(property: 'email', type: 'string'),
                    new OA\Property(property: 'mobile_number', type: 'string'),
                    new OA\Property(property: 'branch_id', type: 'integer'),
                    new OA\Property(property: 'role', type: 'string'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'User updated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $user->update($request->safe()->except('role'));

        if ($request->has('role')) {
            $user->syncRoles([$request->role]);
        }

        $user->load('branch', 'roles');

        return new UserResource($user);
    }

    #[OA\Patch(
        path: '/api/users/{id}/deactivate',
        summary: 'Deactivate user',
        description: 'Deactivate a user account',
        tags: ['Users'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'User deactivated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function deactivate(User $user): JsonResponse
    {
        $this->authorize('users:delete');

        $user->update(['status' => 'inactive']);
        $user->tokens()->delete();

        return response()->json(['message' => 'User deactivated successfully.']);
    }

    #[OA\Patch(
        path: '/api/users/{id}/reactivate',
        summary: 'Reactivate user',
        description: 'Reactivate a deactivated user account',
        tags: ['Users'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'User reactivated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function reactivate(User $user): JsonResponse
    {
        $this->authorize('users:delete');

        $user->update(['status' => 'active']);

        return response()->json(['message' => 'User reactivated successfully.']);
    }

    #[OA\Post(
        path: '/api/users/{id}/reset-password',
        summary: 'Reset user password',
        description: 'Reset a user password (admin action)',
        tags: ['Users'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['password', 'password_confirmation'],
                properties: [
                    new OA\Property(property: 'password', type: 'string', example: 'newpassword123'),
                    new OA\Property(property: 'password_confirmation', type: 'string', example: 'newpassword123'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Password reset successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function resetPassword(ResetPasswordRequest $request, User $user): JsonResponse
    {
        $user->update(['password' => $request->password]);
        $user->tokens()->delete();

        AuditLogService::log('updated', $user, description: "Password reset for {$user->username}");

        return response()->json(['message' => 'Password reset successfully.']);
    }
}
