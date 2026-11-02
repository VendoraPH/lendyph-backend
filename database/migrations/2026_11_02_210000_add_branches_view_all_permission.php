<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * `branches:view_all`: see every branch's data (decision 5b, 2026-11-02).
 *
 * Without it a user sees only the loans, members, collateral, share capital,
 * journals, expenses and staff of the branches they are assigned to
 * (App\Services\BranchAccess). Users with no branch and no `view_all` see no
 * branch data at all.
 *
 * Granted to the roles that oversee the whole cooperative: super_admin, admin,
 * manager, general_bookkeeper, and every board role, meaning every role whose
 * key starts with `bod`. That covers bod1-bod7 and custom board roles such as
 * `bod_chairwoman`, which exists on staging. Any other custom role gets it
 * only when an admin ticks it in the role editor.
 *
 * Mirrors 2026_10_03_110000_add_loan_products_manage_permission. This migration
 * grants it on already-migrated deployments; RoleAndPermissionSeeder carries
 * the same rule for a fresh database. Idempotent: insertOrIgnore throughout,
 * so a re-run adds nothing.
 */
return new class extends Migration
{
    private const PERMISSION = 'branches:view_all';

    private const ROLES = ['super_admin', 'admin', 'manager', 'general_bookkeeper'];

    private const BOARD_ROLE_PREFIX = 'bod';

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

        $roleIds = DB::table('roles')
            ->where('guard_name', $guard)
            ->where(fn ($q) => $q
                ->whereIn('name', self::ROLES)
                ->orWhere('name', 'like', self::BOARD_ROLE_PREFIX.'%'))
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }

        DB::afterCommit(fn () => $this->forgetCachedPermissions());
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')
            ->where('guard_name', 'web')
            ->where('name', self::PERMISSION)
            ->value('id');

        if ($permissionId) {
            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
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
