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
            'branch' => new BranchResource($this->whenLoaded('branch')),
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
