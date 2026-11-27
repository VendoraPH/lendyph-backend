<?php

namespace App\Http\Requests\Concerns;

use App\Models\Role;
use App\Services\RolePermissionGrants;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

/**
 * Who may change which permissions on a role, for POST and PUT /api/roles.
 *
 * AssignableRole already stops a user being handed a role that carries
 * permissions the actor lacks, but `settings:update` alone let the actor add
 * those permissions to a role instead, their own included, and so grant
 * themselves anything. Two rules close that, for everyone but a super_admin
 * (who passes every check through Gate::before anyway):
 *
 * 1. super_admin's own permissions can't be changed. Sending its stored list
 *    unchanged is accepted, because the role editor always sends the full
 *    list, even on a description-only save.
 * 2. No permission the actor doesn't hold may be added to any role. Removing
 *    one is allowed: it only narrows what the role grants.
 */
trait GuardsRolePermissionGrants
{
    protected function refuseUngrantablePermissions(Validator $validator, ?Role $role): void
    {
        $actor = $this->user();

        if (! $this->has('permissions') || $actor === null || $actor->hasRole('super_admin')) {
            return;
        }

        $input = $this->input('permissions');

        // A non-list is the `array` rule's to report.
        if ($input !== null && ! is_array($input)) {
            return;
        }

        $sent = self::sortedNames(collect($input ?? [])->filter(fn ($name) => is_string($name)));
        $stored = $role === null ? [] : self::sortedNames($role->permissions()->pluck('name'));

        if ($role?->name === 'super_admin') {
            if ($sent !== $stored) {
                $validator->errors()->add(
                    'permissions',
                    'Only a super admin can change the super_admin role\'s permissions.'
                );
            }

            return;
        }

        $missing = RolePermissionGrants::ungrantable($actor, $sent, $stored);

        if ($missing !== []) {
            $validator->errors()->add('permissions', RolePermissionGrants::refusal($missing));
        }
    }

    /**
     * @param  Collection<int, string>  $names
     * @return list<string>
     */
    private static function sortedNames(Collection $names): array
    {
        return $names->unique()->sort()->values()->all();
    }
}
