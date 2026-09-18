<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * `expenses:pay` — settling a payable.
 *
 * Mirrors 2026_09_16_200002_add_accounting_permissions: this migration is what
 * grants the permission on staging and production, which are already migrated
 * and will never re-run a seeder. RoleAndPermissionSeeder carries the same name
 * for a fresh database, where migrations run before any role exists and the
 * grant loop below finds nothing to grant. Either one alone is a half-measure.
 *
 * ## Why paying is its own permission and not `expenses:update`
 *
 * The same preparer/approver split the journals permissions already make real.
 * `expenses:create` records a cost; the money has either already moved (a cash
 * expense) or has not moved at all (an accrual). `expenses:pay` is the step
 * that takes money OUT of a cash account against a liability, and it is exactly
 * the step nobody should be able to take on their own paperwork.
 *
 * So `general_bookkeeper` gets it no more than it gets `journals:post` — it
 * records what is owed and someone else settles it. `manager` is read-only and
 * gets nothing here either.
 *
 * ADDITIVE ONLY. This migration inserts one permission and grants it to two
 * roles; it revokes nothing and touches no other permission's grants, so it can
 * land in any order relative to other work on the same tables.
 */
return new class extends Migration
{
    private const PERMISSION = 'expenses:pay';

    /** Administrators only. See the docblock for why the bookkeeper is absent. */
    private const ROLES = ['super_admin', 'admin'];

    public function up(): void
    {
        $guard = 'web';

        DB::table('permissions')->updateOrInsert(
            ['name' => self::PERMISSION, 'guard_name' => $guard],
            ['updated_at' => now(), 'created_at' => now()],
        );

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        $permissionId = DB::table('permissions')
            ->where('name', self::PERMISSION)
            ->where('guard_name', $guard)
            ->value('id');

        if (! $permissionId) {
            return;
        }

        foreach (self::ROLES as $roleName) {
            $roleId = DB::table('roles')
                ->where('name', $roleName)
                ->where('guard_name', $guard)
                ->value('id');

            // A missing role is skipped rather than fatal — same as the
            // accounting permissions migration, which handles `manager` this
            // way. The seeder's list is what a fresh database builds from.
            if (! $roleId) {
                continue;
            }

            $exists = DB::table('role_has_permissions')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->exists();

            if (! $exists) {
                DB::table('role_has_permissions')->insert([
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
        $permIds = DB::table('permissions')->where('name', self::PERMISSION)->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $permIds)->delete();
        DB::table('permissions')->whereIn('id', $permIds)->delete();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
