<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\DeactivateUserRequest;
use App\Http\Requests\User\ReactivateUserRequest;
use App\Http\Requests\User\ResetPasswordRequest;
use App\Http\Requests\User\ShowUserRequest;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/**
 * Every action that binds `{user}` takes a FormRequest it never reads:
 * ShowUserRequest, UpdateUserRequest, DeactivateUserRequest,
 * ReactivateUserRequest, ResetPasswordRequest.
 *
 * Those parameters ARE the permission check — `show`, `deactivate` and
 * `reactivate` used to call `$this->authorize()` in the body instead. They look
 * unused and are not: deleting one does not tidy a signature, it opens the
 * endpoint to every authenticated caller. The move is what lets a refusal be
 * answered with the same 404 a missing id produces; see
 * App\Http\Requests\Concerns\MasksUserExistence. `index` and `store` bind no
 * user, have nothing to conceal, and still authorise the ordinary way.
 */
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

        $filters = request()->validate([
            'search' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            // `min:1` is not cosmetic: 0 is a valid integer that no row can
            // carry, and it used to reach a `when()` that treats it as absent.
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'role' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        /**
         * Pulled out as locals so every filter below can be gated on filled() —
         * PRESENCE — rather than on truthiness.
         *
         * `Builder::when()` skips its callback for any falsy condition, and `0`
         * and `'0'` are falsy, so `?branch_id=0` dropped the scoping filter and
         * listed every user in the organisation — names, usernames, emails and
         * roles — plus org-wide `meta.stats`, for a caller who had asked about one
         * branch. Same hole, same fix, as the loan, collateral, repayment and
         * share-capital lists.
         */
        $search = $filters['search'] ?? null;
        $status = $filters['status'] ?? null;
        $branchId = $filters['branch_id'] ?? null;
        $role = $filters['role'] ?? null;

        $users = User::with('branch', 'roles')
            ->when(filled($search), function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when(filled($status), fn ($query) => $query->where('status', $status))
            ->when(filled($branchId), fn ($query) => $query->forBranch($branchId))
            ->when(filled($role), fn ($query) => $query->role($role))
            ->latest()
            ->paginate(min(max((int) ($filters['per_page'] ?? 15), 1), 100));

        // Status count aggregation so the frontend can render status tabs without
        // a second request. Branch-scoped on the same filled() gate as the list
        // above — the two must agree, or the tabs report the whole organisation
        // while the rows report one branch.
        $stats = User::when(filled($branchId), fn ($q) => $q->forBranch($branchId))
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
            new OA\Response(response: 403, description: 'Caller account is deactivated'),
            new OA\Response(response: 404, description: 'Not found. Also returned when the caller lacks permission for this endpoint, so the two are indistinguishable.'),
        ],
    )]
    public function show(ShowUserRequest $request, User $user): UserResource
    {
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
            new OA\Response(response: 403, description: 'Caller account is deactivated'),
            new OA\Response(response: 404, description: 'Not found. Also returned when the caller lacks permission for this endpoint, and when the '
                .'target is a `super_admin` the caller may not manage, so all three are indistinguishable.'),
            new OA\Response(response: 422, description: 'Validation error, including a payload that would change nothing'),
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
            new OA\Response(response: 403, description: 'Caller account is deactivated'),
            new OA\Response(response: 404, description: 'Not found. Also returned when the caller lacks permission for this endpoint, and when the '
                .'target is a `super_admin` the caller may not manage, so all three are indistinguishable.'),
        ],
    )]
    public function deactivate(DeactivateUserRequest $request, User $user): JsonResponse
    {
        // The super_admin guard that used to sit here moved into
        // DeactivateUserRequest::after(), which answers it as a 404 rather than
        // a 422 that names the platform account. See that class.
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
            new OA\Response(response: 403, description: 'Caller account is deactivated'),
            new OA\Response(response: 404, description: 'Not found. Also returned when the caller lacks permission for this endpoint, so the two are indistinguishable.'),
            new OA\Response(response: 422, description: 'The account is already active, so there is nothing to reactivate'),
        ],
    )]
    public function reactivate(ReactivateUserRequest $request, User $user): JsonResponse
    {
        $user->update(['status' => 'active']);

        return response()->json(['message' => 'User reactivated successfully.']);
    }

    /**
     * A password set by somebody other than its owner is temporary by
     * definition, so the reset arms `must_change_password` in the same breath.
     *
     * Three things about the shape of this, all of them deliberate:
     *
     * 1. ONE write, not two. Password and flag go into the same UPDATE, so
     *    there is no instant at which the admin-chosen password is live but
     *    unflagged — which is the only ordering that could actually leak a
     *    usable credential.
     * 2. `forceFill()`, not `update()`. `must_change_password` is outside
     *    `User::$fillable` on purpose (see the model), and mass assignment
     *    would drop it silently, leaving a reset that does nothing — the same
     *    bug `last_login_at` shipped with. `save()` rather than `saveQuietly()`
     *    keeps the Auditable `updated` row this handler has always produced.
     * 3. Flag first, tokens second, both inside a transaction. If the token
     *    delete were to fail, the rollback takes the flag with it rather than
     *    leaving a half-applied reset. And in the microseconds between the two,
     *    a request arriving on a not-yet-revoked token is already flagged, so
     *    RequirePasswordChange refuses it — the window fails closed.
     */
    #[OA\Post(
        path: '/api/users/{id}/reset-password',
        summary: 'Reset user password',
        description: 'Reset a user password (admin action). The new password is TEMPORARY: it sets '
            .'`must_change_password` on the target, who is then refused every authenticated request '
            .'with **423 Locked** (`code: password_change_required`) until they call '
            .'`POST /api/auth/change-password`. Only `GET /api/auth/me`, that endpoint, and '
            .'`POST /api/auth/logout` remain reachable in the meantime.',
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
            new OA\Response(response: 403, description: 'Caller account is deactivated'),
            new OA\Response(response: 404, description: 'Not found. Also returned when the caller lacks permission for this endpoint, and when the '
                .'target is a `super_admin` the caller may not manage, so all three are indistinguishable.'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function resetPassword(ResetPasswordRequest $request, User $user): JsonResponse
    {
        DB::transaction(function () use ($request, $user) {
            $user->forceFill([
                'password' => $request->password,
                'must_change_password' => true,
                // Revoke the signed KYC file links minted for this user as
                // well as their tokens. A signed URL carries its whole
                // credential in the signature, so deleting tokens could not
                // reach one that had already been handed out: a link issued
                // moments before the reset kept streaming borrower identity
                // documents for the rest of its 30-minute window, to whoever
                // held it. Bumping the counter invalidates all of them at once.
                //
                // Deliberately NOT done in AuthController::changePassword: that
                // path keeps the caller's current session alive on purpose, and
                // links should die exactly when the session that could have
                // produced them is forcibly revoked. Here every token goes, so
                // every link goes with them.
                'file_link_version' => $user->file_link_version + 1,
            ])->save();

            $user->tokens()->delete();
        });

        AuditLogService::log('updated', $user, description: "Password reset for {$user->username}");

        return response()->json(['message' => 'Password reset successfully.']);
    }
}
