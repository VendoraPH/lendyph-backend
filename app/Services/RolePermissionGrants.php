<?php

namespace App\Services;

use App\Models\User;

/**
 * Which permissions a user who isn't a super_admin may not add to a role:
 * anything sent that the role doesn't already carry and the actor doesn't
 * hold. Read by the role form requests and, again under the role lock, by
 * RoleController::update(), so a list that changed between validation and the
 * write is judged by what the role carries at the write.
 */
class RolePermissionGrants
{
    /**
     * @param  list<string>  $sent
     * @param  list<string>  $stored
     * @return list<string>
     */
    public static function ungrantable(User $actor, array $sent, array $stored): array
    {
        return collect($sent)
            ->unique()
            ->diff($stored)
            ->diff($actor->getAllPermissions()->pluck('name'))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * The refusal in words, never permission keys: a permission only a super
     * admin hands out (the Chair's edit during approval) says so, otherwise
     * the first three missing permissions are named as the roles screen names
     * them (PermissionWords), as AssignableRole words its own.
     *
     * @param  list<string>  $missing
     */
    public static function refusal(array $missing): string
    {
        $superAdminOnly = PermissionWords::superAdminOnly($missing);

        if ($superAdminOnly !== null) {
            return "Only a super admin can grant the permission to {$superAdminOnly}.";
        }

        return 'You can\'t grant permissions you do not hold yourself: '.PermissionWords::list($missing).'.';
    }
}
