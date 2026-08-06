<?php

namespace App\Rules;

use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Restricts `role` to the roles the acting user is actually allowed to hand out.
 *
 * `exists:roles,name` accepted any seeded role name, so anyone holding
 * `users:create` / `users:update` — which the client-side `admin` role does,
 * since RoleAndPermissionSeeder grants it every permission — could mint or
 * promote a `super_admin` and inherit the `Gate::before` bypass in
 * AppServiceProvider plus the restructure dual-control exemption in
 * LoanService::approve(). Self-approving a ₱1 restructure writes off the rest
 * of the balance, so that is a direct path from "client admin" to "destroys
 * debt unilaterally".
 *
 * Two things are refused:
 *
 * 1. `super_admin`, unless the actor already holds it. Its permission set is
 *    identical to `admin`'s, so the superset check below cannot tell them
 *    apart — super_admin's extra power lives in the Gate bypass and the
 *    dual-control exemption, and both key off the role *name*.
 * 2. Any role carrying a permission the actor does not hold themselves. Custom
 *    roles created through POST /api/roles may carry any permission in the
 *    registry, so without this a narrowly-scoped operator who was given
 *    `users:update` could still grant away far more than they were ever given.
 */
class AssignableRole implements ValidationRule
{
    private const PLATFORM_ROLE = 'super_admin';

    public function __construct(private readonly ?User $actor) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Non-strings (`role[]=super_admin` reaches here as an array) are the
        // `string` rule's to report — which is why `string` must stay in the
        // rule list alongside this one.
        if (! is_string($value)) {
            return;
        }

        // Scoped to the guard `syncRoles()` will resolve against, so this
        // cannot end up vetting one role while the controller assigns another.
        $role = Role::where('name', $value)->where('guard_name', 'web')->first();

        // Unknown role names are `exists:roles,name`'s job to report; saying it
        // twice would just put two messages on the same field.
        if ($role === null) {
            return;
        }

        if (! $this->actor instanceof User) {
            $fail('The :attribute cannot be assigned.');

            return;
        }

        $actorIsPlatform = $this->actor->hasRole(self::PLATFORM_ROLE);

        if ($role->name === self::PLATFORM_ROLE && ! $actorIsPlatform) {
            $fail('The super_admin role can only be assigned by a super_admin.');

            return;
        }

        if ($actorIsPlatform) {
            return;
        }

        $missing = $role->permissions
            ->pluck('name')
            ->diff($this->actor->getAllPermissions()->pluck('name'))
            ->sort()
            ->values();

        if ($missing->isNotEmpty()) {
            $fail(sprintf(
                'The selected role grants permissions you do not hold yourself (%s).',
                $missing->take(3)->implode(', ').($missing->count() > 3 ? ', …' : ''),
            ));
        }
    }
}
