<?php

use App\Models\Role;
use App\Services\RolePermissionAudit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * CIC reporting permissions (the CIC build plan, decision 8): `cic:view`
 * (screens, readiness, records, captures, submissions), `cic:prepare`
 * (generate files, per-loan overrides, test captures), `cic:submit` (encrypt,
 * download, mark submitted), `cic:review` (record CIC's acknowledgement) and
 * `cic:settings` (settings, keys, product and code mappings). Granted to admin
 * and super_admin only; any other role gets them only when someone who holds
 * them ticks them in the role editor (the grant guard). Every CIC route also
 * needs `branches:view_all`.
 *
 * Mirrors 2026_11_25_700100_add_menu_visibility_manage_permission. This
 * migration grants them on already-migrated deployments;
 * RoleAndPermissionSeeder lists them for a fresh database, where no role
 * exists yet when this runs.
 *
 * Each role whose list changes gets one `role_permissions_changed` audit row
 * (RolePermissionAudit), with no user, so it reads as a system change.
 * Idempotent: insertOrIgnore throughout, and a re-run that changes no list
 * writes no row. Nothing is revoked.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['cic:view', 'cic:prepare', 'cic:submit', 'cic:review', 'cic:settings'];

    private const ROLES = ['super_admin', 'admin'];

    private const NAME = '2026_11_28_900100_add_cic_permissions';

    public function up(): void
    {
        $guard = 'web';

        foreach (self::PERMISSIONS as $permission) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $permission,
                'guard_name' => $guard,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $permissionIds = DB::table('permissions')
            ->where('guard_name', $guard)
            ->whereIn('name', self::PERMISSIONS)
            ->pluck('id');

        $roles = Role::query()->where('guard_name', $guard)->whereIn('name', self::ROLES)->get()
            ->filter(fn (Role $role) => in_array($role->name, self::ROLES, true));

        foreach ($roles as $role) {
            $before = RolePermissionAudit::names($role);

            foreach ($permissionIds as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'role_id' => $role->id,
                    'permission_id' => $permissionId,
                ]);
            }

            RolePermissionAudit::record(
                $role,
                $before,
                RolePermissionAudit::names($role),
                sprintf('Permissions for role %s changed by migration %s: added the CIC Reporting permissions', $role->auditName(), self::NAME),
            );
        }

        DB::afterCommit(fn () => $this->forgetCachedPermissions());
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', self::PERMISSIONS)
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
