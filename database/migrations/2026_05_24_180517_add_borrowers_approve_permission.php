<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $guard = 'web';

        DB::table('permissions')->updateOrInsert(
            ['name' => 'borrowers:approve', 'guard_name' => $guard],
            ['updated_at' => now(), 'created_at' => now()],
        );

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        $permId = DB::table('permissions')
            ->where('name', 'borrowers:approve')
            ->where('guard_name', $guard)
            ->value('id');

        if (! $permId) {
            return;
        }

        // Grants per the frontend handoff (2026-05-24): admin + loan_officer
        // own the membership-approval action. super_admin is included so prod
        // DBs that already exist (where syncPermissions(Permission::all())
        // ran at seed time but doesn't run on subsequent migrations) still
        // pick up the new permission.
        $rolesWithGrant = ['super_admin', 'admin', 'loan_officer'];

        foreach ($rolesWithGrant as $roleName) {
            $roleId = DB::table('roles')
                ->where('name', $roleName)
                ->where('guard_name', $guard)
                ->value('id');

            if (! $roleId) {
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

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        $permId = DB::table('permissions')
            ->where('name', 'borrowers:approve')
            ->where('guard_name', 'web')
            ->value('id');

        if (! $permId) {
            return;
        }

        DB::table('role_has_permissions')->where('permission_id', $permId)->delete();
        DB::table('permissions')->where('id', $permId)->delete();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
