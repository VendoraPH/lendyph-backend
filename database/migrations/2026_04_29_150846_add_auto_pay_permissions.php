<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $guard = 'web';

        $newPermissions = [
            'auto_pay:view',
            'auto_pay:process',
            'auto_pay:toggle',
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

        $roleGrants = [
            'super_admin' => $newPermissions,
            'admin' => $newPermissions,
            'loan_officer' => ['auto_pay:view', 'auto_pay:toggle'],
            'cashier' => ['auto_pay:view', 'auto_pay:process'],
            'general_bookkeeper' => ['auto_pay:view'],
            'viewer' => ['auto_pay:view'],
        ];

        foreach ($roleGrants as $roleName => $permissions) {
            $roleId = DB::table('roles')
                ->where('name', $roleName)
                ->where('guard_name', $guard)
                ->value('id');

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
            'auto_pay:view',
            'auto_pay:process',
            'auto_pay:toggle',
        ];

        $permIds = DB::table('permissions')->whereIn('name', $names)->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $permIds)->delete();
        DB::table('permissions')->whereIn('id', $permIds)->delete();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
