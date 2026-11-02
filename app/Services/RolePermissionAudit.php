<?php

namespace App\Services;

use App\Models\Role;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * The audit row for a change to a role's permissions.
 *
 * Permissions live in the `role_has_permissions` pivot, so `syncPermissions()`
 * fires no model event and the Auditable trait on Role never sees it. Whoever
 * changes the pivot reads the list before, changes it, and hands both lists
 * here: one `role_permissions_changed` row against the role, with the sorted
 * old and new lists, and nothing at all when the list didn't change (the role
 * editor sends the whole list on every save).
 *
 * RoleController calls it for API changes. A migration that grants or
 * revokes a permission calls it too, with a description naming the
 * migration: outside a request it has no user, so the row reads as a system
 * change (`user_id` null).
 */
class RolePermissionAudit
{
    /**
     * @return list<string>
     */
    public static function names(SpatieRole $role): array
    {
        return $role->permissions()->pluck('name')->unique()->sort()->values()->all();
    }

    /**
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    public static function record(SpatieRole $role, array $before, array $after, ?string $description = null): void
    {
        sort($before);
        sort($after);
        $before = array_values(array_unique($before));
        $after = array_values(array_unique($after));

        if ($before === $after) {
            return;
        }

        $role = $role instanceof Role ? $role : Role::query()->findOrFail($role->getKey());

        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        AuditLogService::log(
            action: 'role_permissions_changed',
            auditable: $role,
            oldValues: ['permissions' => $before],
            newValues: ['permissions' => $after],
            description: $description ?? sprintf(
                'Permissions for role %s changed: added [%s], removed [%s]',
                $role->auditName(),
                implode(', ', $added),
                implode(', ', $removed),
            ),
        );
    }
}
