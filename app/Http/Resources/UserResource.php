<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
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
            'permissions' => $this->when(
                $this->relationLoaded('permissions'),
                fn () => $this->getAllPermissions()->pluck('name'),
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
