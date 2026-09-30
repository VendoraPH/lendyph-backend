<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Give back the collections:* grants the roles screen stripped.
 *
 * Until 2026-09-30, Settings → Role and Permissions dropped every
 * `collections:*` permission from a role as it loaded it, and saving sent the
 * rest back to PUT /roles/{id}, whose syncPermissions() replaces the whole set.
 * So any save of a role through that screen revoked its collections grants,
 * whatever the admin changed. The screen never displayed collections, so no
 * one could have removed them there on purpose.
 *
 * This restores exactly what RoleAndPermissionSeeder grants, on the roles that
 * lost it. It only ever ADDS a missing row: a role that still holds its grants
 * is untouched, a role that does not exist on this box is skipped, and no other
 * role is given anything. Found missing on 2026-09-30: `admin` on the portfolio
 * box. Both staging databases still had every grant, so there it is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        $guard = 'web';

        $roleGrants = [
            'super_admin' => ['collections:view', 'collections:mark_collected'],
            'admin' => ['collections:view', 'collections:mark_collected'],
            'collector' => ['collections:view', 'collections:mark_collected'],
            'loan_officer' => ['collections:view'],
        ];

        foreach (['collections:view', 'collections:mark_collected'] as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission, 'guard_name' => $guard],
                ['updated_at' => now(), 'created_at' => now()],
            );
        }

        $permissionIds = DB::table('permissions')
            ->where('guard_name', $guard)
            ->whereIn('name', ['collections:view', 'collections:mark_collected'])
            ->pluck('id', 'name');

        foreach ($roleGrants as $roleName => $permissions) {
            $roleId = DB::table('roles')
                ->where('name', $roleName)
                ->where('guard_name', $guard)
                ->value('id');

            if (! $roleId) {
                continue;
            }

            foreach ($permissions as $permission) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionIds[$permission],
                ]);
            }
        }

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    /**
     * Nothing to undo. The grants this adds are the seeded defaults, and there
     * is no record of which of them were missing before it ran, so removing any
     * would revoke grants some roles have always held.
     */
    public function down(): void {}
};
