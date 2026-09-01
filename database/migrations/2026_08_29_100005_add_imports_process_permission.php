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
            'imports:process',
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

        // Admins only, and deliberately not loan_officer.
        //
        // A CSV migration writes borrowers and loans in bulk with no approval
        // workflow in front of them — it is the one path in this system that
        // can create released debt without anyone approving it. That is a
        // one-off data-migration power, not day-to-day lending work, so it sits
        // with the roles that already answer for the state of the books.
        $roleGrants = [
            'super_admin' => $newPermissions,
            'admin' => $newPermissions,
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
            'imports:process',
        ];

        $permIds = DB::table('permissions')->whereIn('name', $names)->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $permIds)->delete();
        DB::table('permissions')->whereIn('id', $permIds)->delete();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
