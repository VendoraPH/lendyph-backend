<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * `credit_scoring:view`, `credit_scoring:override` and `credit_scoring:settings`.
 *
 * The frontend's Settings → User Roles screen offers these three ticks, and
 * PUT /roles/{id} refused the whole save (422) while they did not exist. They
 * ship together with the Credit Scoring routes, which answer 501 to a caller
 * holding the permission until the module is built.
 *
 * Granted to super_admin and admin only, by the owner's decision on
 * 2026-10-02. No other role gets them here; an admin can grant them to any
 * role through the roles screen.
 *
 * Mirrors 2026_09_18_210002_add_expense_payment_permission: this migration is
 * what grants them on staging and production, which are already migrated and
 * will never re-run a seeder. RoleAndPermissionSeeder carries the same names
 * for a fresh database, where migrations run before any role exists and the
 * grant loop below finds nothing to grant.
 *
 * ADDITIVE ONLY. It inserts missing rows and nothing else: an existing
 * permission or grant is left as it is, a role that does not exist on this box
 * is skipped, and nothing is revoked.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'credit_scoring:view',
        'credit_scoring:override',
        'credit_scoring:settings',
    ];

    private const ROLES = ['super_admin', 'admin'];

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

        foreach (self::ROLES as $roleName) {
            $roleId = DB::table('roles')
                ->where('name', $roleName)
                ->where('guard_name', $guard)
                ->value('id');

            if (! $roleId) {
                continue;
            }

            foreach ($permissionIds as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        $permIds = DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', self::PERMISSIONS)
            ->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $permIds)->delete();
        DB::table('permissions')->whereIn('id', $permIds)->delete();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
