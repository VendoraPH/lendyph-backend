<?php

use App\Models\Role;
use App\Services\RolePermissionAudit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * `loans:edit_in_approval` (finish run round 2, decision 14; ClickUp
 * 14zfbg0e89e): the Chairman/Chairwoman edits a loan application while it
 * waits at their approval step (PATCH /loans/{loan}/approval-edit), then
 * approves or forwards it with the chain's own endpoints.
 *
 * Exclusive to the Chair's role: `bod1`, the board role the default chain's
 * "BOD Chairwoman" step names. Not admin, not super_admin, not any other
 * role, so an admin can't hand it on either (they can't grant a permission
 * they don't hold, RolePermissionGrants). A deployment with no `bod1` role
 * grants it to nobody.
 *
 * Mirrors RoleAndPermissionSeeder::CHAIR_ROLE and CHAIR_ONLY_PERMISSIONS. This
 * migration grants it on already-migrated deployments; the seeder lists it for
 * a fresh database, where no role exists yet when this runs.
 *
 * The Chair's role gets one `role_permissions_changed` audit row
 * (RolePermissionAudit), with no user, so it reads as a system change.
 * Idempotent: insertOrIgnore throughout, and a re-run that changes no list
 * writes no row. Nothing is revoked.
 */
return new class extends Migration
{
    private const PERMISSION = 'loans:edit_in_approval';

    private const CHAIR_ROLE = 'bod1';

    private const NAME = '2026_11_24_300000_add_loans_edit_in_approval_permission';

    public function up(): void
    {
        $guard = 'web';

        DB::table('permissions')->insertOrIgnore([
            'name' => self::PERMISSION,
            'guard_name' => $guard,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $permissionId = DB::table('permissions')
            ->where('guard_name', $guard)
            ->where('name', self::PERMISSION)
            ->value('id');

        // Matched in PHP as well: the column's collation is case-insensitive,
        // and only the role keyed exactly `bod1` is the Chair's.
        $chair = Role::query()->where('guard_name', $guard)->where('name', self::CHAIR_ROLE)->get()
            ->first(fn (Role $role) => $role->name === self::CHAIR_ROLE);

        if ($chair !== null && $permissionId !== null) {
            $before = RolePermissionAudit::names($chair);

            DB::table('role_has_permissions')->insertOrIgnore([
                'role_id' => $chair->id,
                'permission_id' => $permissionId,
            ]);

            RolePermissionAudit::record(
                $chair,
                $before,
                RolePermissionAudit::names($chair),
                sprintf('Permissions for role %s changed by migration %s: added the Chair\'s loan edit during approval', $chair->auditName(), self::NAME),
            );
        }

        DB::afterCommit(fn () => $this->forgetCachedPermissions());
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->where('guard_name', 'web')
            ->where('name', self::PERMISSION)
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        DB::afterCommit(fn () => $this->forgetCachedPermissions());
    }

    private function forgetCachedPermissions(): void
    {
        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
