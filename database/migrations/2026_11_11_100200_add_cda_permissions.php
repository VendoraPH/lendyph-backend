<?php

use App\Models\Role;
use App\Services\RolePermissionAudit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * CDA Reports permissions (Phase 3, CDA design §3), sixteen of them:
 * `cda:view`, `cda:prepare`, `cda:upload`, `cda:review`, `cda:approve`,
 * `cda:finalize`, `cda:reopen`, `cda:submit`, `cda:export`, `cda:settings`,
 * `cda:manage_officers`, `cda:manage_social`, `cda:manage_mediation`, and the
 * restricted views `cda:view_mediation`, `cda:view_audit` and `cda:view_tax`.
 * Granted to admin and super_admin only; any other role gets them only when
 * an admin ticks them in the role editor. Every CDA endpoint also needs
 * `branches:view_all` (whole-cooperative reports). The template manager is a
 * super_admin role check, not a permission.
 *
 * Mirrors 2026_11_10_500300_add_savings_permissions. This migration grants
 * them on already-migrated deployments; RoleAndPermissionSeeder lists them
 * for a fresh database, where no role exists yet when this runs.
 *
 * Each role whose list changes gets one `role_permissions_changed` audit row
 * (RolePermissionAudit), with no user, so it reads as a system change.
 * Idempotent: insertOrIgnore throughout, and a re-run that changes no list
 * writes no row.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'cda:view', 'cda:prepare', 'cda:upload', 'cda:review', 'cda:approve',
        'cda:finalize', 'cda:reopen', 'cda:submit', 'cda:export', 'cda:settings',
        'cda:manage_officers', 'cda:manage_social', 'cda:manage_mediation',
        'cda:view_mediation', 'cda:view_audit', 'cda:view_tax',
    ];

    private const ROLES = ['super_admin', 'admin'];

    private const NAME = '2026_11_11_100200_add_cda_permissions';

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
                sprintf('Permissions for role %s changed by migration %s: added CDA permissions', $role->auditName(), self::NAME),
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
