<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * The accounting permission vocabulary.
 *
 * Mirrors 2026_08_29_100005_add_imports_process_permission: this migration is
 * what grants these on staging and production, which are already migrated and
 * will never re-run a seeder. RoleAndPermissionSeeder carries the same list for
 * a fresh database, where migrations run before any role exists and the grant
 * loop below finds nothing to grant. Either one alone is a half-measure.
 */
return new class extends Migration
{
    public function up(): void
    {
        $guard = 'web';

        $newPermissions = [
            // Module-level: entering accounting, reconciling a money account,
            // closing a period, and editing the posting defaults.
            'accounting:view',
            'accounting:reconcile',
            'accounting:close',
            'accounting:settings',

            'chart_of_accounts:view',
            'chart_of_accounts:create',
            'chart_of_accounts:update',
            'chart_of_accounts:delete',

            'journals:view',
            'journals:create',
            'journals:post',
            'journals:reverse',

            'expenses:view',
            'expenses:create',
            'expenses:update',

            'cash_accounts:view',
            'cash_accounts:transfer',
        ];

        foreach ($newPermissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission, 'guard_name' => $guard],
                ['updated_at' => now(), 'created_at' => now()],
            );
        }

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', $newPermissions)
            ->where('guard_name', $guard)
            ->pluck('id', 'name');

        /*
         * Preparer and approver are different people, and this split is what
         * makes that real rather than a convention.
         *
         * `general_bookkeeper` drafts entries, records expenses and reconciles
         * — every one of those is reversible before anything reaches the
         * ledger. It deliberately holds NO `journals:post`, `journals:reverse`
         * or `accounting:close`: posting makes an entry immutable, reversing
         * writes a second one against it, and closing locks a period. Those are
         * the three actions nobody should be able to take on their own work.
         *
         * `manager` is read-only. A branch manager looks at the books; they do
         * not keep them. Note the role may not exist yet on a given box, which
         * the loop below handles the same way it handles any missing role.
         */
        $roleGrants = [
            'super_admin' => $newPermissions,
            'admin' => $newPermissions,
            'general_bookkeeper' => [
                'accounting:view',
                'accounting:reconcile',
                'chart_of_accounts:view',
                'journals:view',
                'journals:create',
                'expenses:view',
                'expenses:create',
                'cash_accounts:view',
            ],
            'manager' => [
                'accounting:view',
                'chart_of_accounts:view',
                'journals:view',
                'expenses:view',
                'cash_accounts:view',
            ],
        ];

        foreach ($roleGrants as $roleName => $permissions) {
            $roleId = DB::table('roles')
                ->where('name', $roleName)
                ->where('guard_name', $guard)
                ->value('id');

            // `manager` is being added by separate work and may not exist here
            // yet. A missing role is skipped, not fatal — the seeder's list is
            // what a fresh database builds from either way.
            if (! $roleId) {
                continue;
            }

            foreach ($permissions as $permName) {
                $permId = $permissionIds[$permName] ?? null;
                if (! $permId) {
                    continue;
                }

                $exists = DB::table('role_has_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permId)
                    ->exists();

                if (! $exists) {
                    DB::table('role_has_permissions')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $permId,
                    ]);
                }
            }
        }

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        $names = [
            'accounting:view', 'accounting:reconcile', 'accounting:close', 'accounting:settings',
            'chart_of_accounts:view', 'chart_of_accounts:create', 'chart_of_accounts:update', 'chart_of_accounts:delete',
            'journals:view', 'journals:create', 'journals:post', 'journals:reverse',
            'expenses:view', 'expenses:create', 'expenses:update',
            'cash_accounts:view', 'cash_accounts:transfer',
        ];

        $permIds = DB::table('permissions')->whereIn('name', $names)->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $permIds)->delete();
        DB::table('permissions')->whereIn('id', $permIds)->delete();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
