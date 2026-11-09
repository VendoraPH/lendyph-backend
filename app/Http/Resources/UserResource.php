<?php

namespace App\Http\Resources;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Set only by `GET /users` (UserController::index), from UserReach. Null
     * everywhere else, so /auth/me, login, GET /users/{id} and the nested
     * actors carry no `can_manage` key.
     */
    private ?bool $canManage = null;

    /**
     * Set only by GET /auth/me (AuthController::me()), so `role_details` names
     * the caller's own roles and no one else's.
     */
    private bool $roleDetails = false;

    /**
     * Whether the caller can reach this user (UserReach): false exactly when
     * edit, reset password, deactivate and reactivate would answer the masked
     * 404 for this target. Each action still needs its own permission
     * (users:update, users:delete, users:reset_password).
     */
    public function withCanManage(bool $canManage): static
    {
        $this->canManage = $canManage;

        return $this;
    }

    /**
     * Add `role_details`: the same roles as `roles`, in the same order, each as
     * `{key, display_name}`. `display_name` is the role's editable label, or
     * its key in words when it has none, as the role editor shows it
     * (Role::auditLabel()). "Access Restricted" reads it to name the user's
     * role without `users:view`, which the roles list needs.
     */
    public function withRoleDetails(): static
    {
        $this->roleDetails = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'username' => $this->username,
            'email' => $this->email,
            'mobile_number' => $this->mobile_number,
            'status' => $this->status,
            'last_login_at' => $this->last_login_at,
            // Added here rather than bolted onto the login response, because
            // this one resource already IS the envelope for both places the
            // client needs it: GET /auth/me serialises it as `data.*`, and
            // AuthController::login nests it as `user.*`. The frontend's
            // `User` interface is a field-for-field mirror of this array, so
            // it picks the flag up in every context it already reads a user.
            'must_change_password' => (bool) $this->must_change_password,
            /**
             * BOTH shapes, for one release, and neither is decorative.
             *
             * `branch` is the singular one every client reads today. The
             * frontend persists its auth store to localStorage with no version
             * and no migration step, so a session that was already signed in
             * when this deploys rehydrates a `{branch: {...}}` object from
             * before the change and never re-reads `/auth/me` for it. Dropping
             * the key would crash those sessions rather than degrade them. It
             * is also not only the auth store's: this resource is reused as the
             * nested actor payload by AuditLogResource, BorrowerResource and
             * LoanResource, so removing the key changes four response shapes at
             * once, on screens nobody is testing for this.
             *
             * `branches` is the assignment itself, and the shape everything
             * should be reading by the time `branch` is removed.
             *
             * They cannot disagree: `users.branch_id` is maintained as a MEMBER
             * of the assigned set (see ResolvesBranchAssignment), so `branch`
             * is always the first element of `branches`, never a branch the
             * user is no longer in.
             *
             * Both use `whenLoaded`, so a caller that eager-loads neither — the
             * nested-actor case — gets the same payload it gets today, with
             * both keys absent rather than one of them null.
             */
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'branches' => $this->whenLoaded(
                'branches',
                fn () => BranchResource::collection($this->branches),
            ),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')),
            'role_details' => $this->when(
                $this->roleDetails && $this->relationLoaded('roles'),
                fn () => $this->roles->map(fn (Role $role) => ['key' => $role->name, 'display_name' => $role->auditLabel()])->values(),
            ),
            'permissions' => $this->when(
                $this->relationLoaded('permissions'),
                fn () => $this->getAllPermissions()->pluck('name'),
            ),
            'can_manage' => $this->when($this->canManage !== null, fn () => $this->canManage),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
