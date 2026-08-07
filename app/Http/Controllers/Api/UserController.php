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
use Illuminate\Validation\ValidationException;
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
            ->paginate(min((int) request('per_page', 15), 100));

        // Status count aggregation so the frontend can render status tabs without a second request.
        $stats = User::when(request('branch_id'), fn ($q, $b) => $q->forBranch($b))
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return UserResource::collection($users)
            ->additional(['meta' => ['stats' => [
                'active' => (int) ($stats['active'] ?? 0),
                'inactive' => (int) ($stats['inactive'] ?? 0),
            ]]]);
    }

    #[OA\Post(
        path: '/api/users',
        summary: 'Create user',
        description: 'Create a new user account. `role` is limited to roles the caller may grant: '
            .'`super_admin` requires the caller to already be a `super_admin`, and no role may carry '
            .'permissions the caller does not hold themselves.',
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
                    new OA\Property(property: 'role', type: 'string', example: 'loan_officer'),
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

        // The Auditable trait's `created` row carries the user's columns, and
        // the role is not one of them — it lands in model_has_roles, which is
        // mutable and keeps no history. Without this entry the trail cannot
        // say which role an account was opened with.
        AuditLogService::log(
            action: 'role_assigned',
            auditable: $user,
            newValues: ['role' => $request->role],
            description: "User {$user->username} created with role {$request->role}",
        );

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
        description: 'Update an existing user. Same `role` restrictions as user creation, plus: you may '
            .'not change the role on your own record, and only a `super_admin` may change the role of a '
            .'`super_admin`. Non-role fields on your own record are still editable.',
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
        $previousRole = $user->getRoleNames()->first();

        $user->update($request->safe()->except('role'));

        if ($request->has('role')) {
            $user->syncRoles([$request->role]);

            // Roles live in a pivot table, so a role-only payload leaves the
            // user row non-dirty and the Auditable trait never fires — a
            // promotion used to pass through completely unrecorded. Log it
            // explicitly. `AuditLogService` stamps the actor (auth user) and
            // the target (auditable), so both ends of the change are captured.
            if ($request->role !== $previousRole) {
                AuditLogService::log(
                    action: 'role_changed',
                    auditable: $user,
                    oldValues: ['role' => $previousRole],
                    newValues: ['role' => $request->role],
                    description: sprintf(
                        'Role for %s changed from %s to %s',
                        $user->username,
                        $previousRole ?? 'none',
                        $request->role,
                    ),
                );
            }
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
            new OA\Response(response: 422, description: 'Target is a super_admin and the caller is not'),
        ],
    )]
    public function deactivate(User $user): JsonResponse
    {
        $this->authorize('users:delete');

        // Deactivation revokes the target's tokens on their next request, so a
        // client admin could otherwise lock the platform team out of their own
        // deployment. Same boundary as editing or resetting that account.
        if (! auth()->user()->canManageAccount($user)) {
            throw ValidationException::withMessages([
                'user' => 'Only a super_admin can deactivate a super_admin.',
            ]);
        }

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
