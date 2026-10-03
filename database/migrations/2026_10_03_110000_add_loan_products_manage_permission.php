<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * `loan_products:manage`: create, update and delete loan products.
 *
 * Product management rode on the loan permissions: `loans:create` for
 * POST /loan-products and DELETE /loan-products/{id}, `loans:update` for
 * PUT /loan-products/{id}. So every role that prepares or edits loans could
 * also rewrite the catalogue every application is built from. Narrowed to its
 * own permission, granted to super_admin and admin only, by the owner's
 * decision on 2026-10-03. Reading products still needs only `loans:view`.
 *
 * Mirrors 2026_10_02_160000_add_credit_scoring_permissions: this migration is
 * what grants it on staging and production, which are already migrated and
 * will never re-run a seeder. RoleAndPermissionSeeder carries the same name
 * for a fresh database, where migrations run before any role exists and the
 * grant loop below finds nothing to grant.
 *
 * ADDITIVE ONLY. Nothing is revoked here: the other roles stop managing
 * products because the routes now check this permission instead, and they
 * keep every loan permission they hold. An admin can grant it to any role
 * through the roles screen.
 */
return new class extends Migration
{
    private const PERMISSION = 'loan_products:manage';

    private const ROLES = ['super_admin', 'admin'];

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

        foreach (self::ROLES as $roleName) {
            $roleId = DB::table('roles')
                ->where('name', $roleName)
                ->where('guard_name', $guard)
                ->value('id');

            if (! $roleId) {
                continue;
            }

            DB::table('role_has_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }

        $this->forgetCachedPermissions();
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

        $this->forgetCachedPermissions();
    }

    private function forgetCachedPermissions(): void
    {
        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
