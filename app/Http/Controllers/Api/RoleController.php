<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Rules\UniqueRoleDisplayName;
use App\Services\RolePermissionAudit;
use App\Services\RolePermissionGrants;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use Spatie\Permission\PermissionRegistrar;

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
                    new OA\Property(property: 'display_name', type: 'string', maxLength: 100, nullable: true, example: 'Branch Manager'),
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
            new OA\Response(response: 422, description: 'Validation error, or a permission the actor does not hold (`errors.permissions`)'),
        ],
    )]
    public function store(StoreRoleRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $role = DB::transaction(function () use ($validated) {
            self::lockAndRecheckLabels($validated);
            self::forgetPermissionCacheAfterCommit();

            $role = Role::create([
                'name' => $validated['name'],
                'guard_name' => 'web',
                'display_name' => $validated['display_name'] ?? null,
                'description' => $validated['description'] ?? null,
                'is_active' => true,
                'is_system' => false,
            ]);

            if (! empty($validated['permissions'])) {
                $role->syncPermissions($validated['permissions']);
                RolePermissionAudit::record($role, [], RolePermissionAudit::names($role));
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
                    new OA\Property(property: 'display_name', type: 'string', maxLength: 100, nullable: true, description: 'Label shown for the role. Editable on system roles too; never changes `name`.'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string')),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Role updated'),
            new OA\Response(response: 409, description: 'The role was deleted by another request while this one waited; nothing was written. Reload and try again'),
            new OA\Response(response: 422, description: 'Validation error; system-role rename attempted; a non-super_admin changing super_admin\'s display_name (clearing included) or permissions; or adding a permission the actor does not hold (`errors.display_name` / `errors.permissions`)'),
        ],
    )]
    public function update(UpdateRoleRequest $request, Role $role): RoleResource
    {
        $validated = $request->validated();
        $actor = $request->user();

        // A non-super_admin's save reaches here on super_admin only once
        // UpdateRoleRequest has proved its label and permissions unchanged.
        // Writing them back anyway could still move them: the role editor
        // sends `display_name: null` for the default label, which would
        // erase a stored "Super Admin". So they are left alone.
        $guardedSuperAdmin = $role->name === 'super_admin' && ! $actor->hasRole('super_admin');

        $role = DB::transaction(function () use ($role, $validated, $actor, $guardedSuperAdmin) {
            self::lockAndRecheckLabels($validated, $role->id);
            $role = self::relockedOrConflict($role);
            self::forgetPermissionCacheAfterCommit();

            $fields = collect($validated)
                ->only($guardedSuperAdmin ? ['name', 'description'] : ['name', 'display_name', 'description'])
                ->toArray();
            if ($fields !== []) {
                $role->update($fields);
            }

            if (array_key_exists('permissions', $validated) && ! $guardedSuperAdmin) {
                $before = RolePermissionAudit::names($role);
                $sent = array_values($validated['permissions'] ?? []);

                // The form request judged the grant against the list it read
                // before the lock; judge it again against the locked list.
                if (! $actor->hasRole('super_admin')) {
                    $missing = RolePermissionGrants::ungrantable($actor, $sent, $before);
                    if ($missing !== []) {
                        throw ValidationException::withMessages([
                            'permissions' => RolePermissionGrants::refusal($missing),
                        ]);
                    }
                }

                $role->syncPermissions($sent);
                RolePermissionAudit::record($role, $before, RolePermissionAudit::names($role));
            }

            return $role;
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

        if (in_array($role->name, Role::PROTECTED_KEYS, true)) {
            throw ValidationException::withMessages([
                'role' => "The {$role->name} role cannot be deactivated.",
            ]);
        }

        // The change and its audit row commit together. A deactivated role
        // grants nothing (User::getPermissionsViaRoles()), so the permission
        // cache is cleared once the change is visible to other requests.
        DB::transaction(function () use ($role) {
            self::forgetPermissionCacheAfterCommit();
            $role->update(['is_active' => false]);
        });
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

        DB::transaction(function () use ($role) {
            self::forgetPermissionCacheAfterCommit();
            $role->update(['is_active' => true]);
        });
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
            new OA\Response(response: 409, description: 'The role was already deleted by another request; nothing was written. Reload and try again'),
            new OA\Response(response: 422, description: 'System role or role in use'),
        ],
    )]
    public function destroy(Role $role): JsonResponse
    {
        $this->authorize('settings:delete');

        if ($role->is_system || in_array($role->name, Role::PROTECTED_KEYS, true)) {
            throw ValidationException::withMessages([
                'role' => "System role '{$role->name}' cannot be deleted.",
            ]);
        }

        DB::transaction(function () use ($role) {
            // Every role row first, in id order, as store() and update() take
            // them: a delete racing a save of the same role then waits instead
            // of deadlocking on the pivot rows Spatie's delete hook removes,
            // and the user count and permission list below are read after any
            // save that got there first.
            self::lockAndRecheckLabels([]);
            $role = self::relockedOrConflict($role);
            self::forgetPermissionCacheAfterCommit();

            $usersCount = $role->users()->count();
            if ($usersCount > 0) {
                throw ValidationException::withMessages([
                    'role' => "Role '{$role->name}' is assigned to {$usersCount} user(s). Reassign them before deleting.",
                ]);
            }

            // The trail keeps what the deleted role carried: deleting it
            // detaches its permissions with no row of their own.
            RolePermissionAudit::record($role, RolePermissionAudit::names($role), []);
            $role->delete();
        });

        return response()->json(['message' => 'Role deleted successfully.']);
    }

    /**
     * The role as it stands now that every role row is locked.
     *
     * The route binding loaded the role before this request waited for the
     * locks, so a delete that committed meanwhile leaves `$role` pointing at
     * a row that's gone. Acting on it would audit a change that never
     * happened (a second `deleted` row, a phantom `updated` row) or insert
     * permission rows for a missing role id (a foreign key error, 500).
     * Re-read it under the lock; when it's gone, nothing is written and the
     * client is told to reload.
     *
     * @throws HttpResponseException 409 when the role no longer exists
     */
    private static function relockedOrConflict(Role $role): Role
    {
        $current = Role::query()->whereKey($role->getKey())->lockForUpdate()->first();

        if ($current === null) {
            throw new HttpResponseException(response()->json([
                'message' => 'This role was changed or deleted by another request. Reload and try again.',
            ], 409));
        }

        return $current;
    }

    /**
     * Clear Spatie's permission cache once the current transaction commits.
     *
     * syncPermissions() and the role model's own hooks clear it while the
     * transaction is still open, so a request in between can rebuild it from
     * the old committed rows and keep that copy for the cache's lifetime
     * (24 hours, config/permission.php). Spatie's own clear stays; this one
     * runs after the new rows are visible. A rolled-back write clears nothing,
     * and has nothing to clear.
     */
    private static function forgetPermissionCacheAfterCommit(): void
    {
        DB::afterCommit(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions());
    }

    /**
     * The form request checks that a key or label doesn't read as another
     * role's before the transaction opens, so two writes racing through that
     * window (relabel cashier "Teller" while a role keyed `teller` is created)
     * could both pass. Lock every role row, then repeat the check against the
     * locked rows: the second writer waits for the first, sees its row and is
     * refused with the same 422 the form request gives.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private static function lockAndRecheckLabels(array $validated, ?int $ignoreRoleId = null): void
    {
        $roles = Role::query()->orderBy('id')->lockForUpdate()->get(['id', 'name', 'display_name']);

        $errors = [];
        foreach (['name', 'display_name'] as $field) {
            $value = $validated[$field] ?? null;

            if (is_string($value) && trim($value) !== ''
                && UniqueRoleDisplayName::readsAsAnotherRole($roles, $value, $ignoreRoleId)) {
                $errors[$field] = UniqueRoleDisplayName::message($field);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
