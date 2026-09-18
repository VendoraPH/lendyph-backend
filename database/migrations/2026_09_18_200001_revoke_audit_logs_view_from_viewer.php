<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Take `audit_logs:view` away from the `viewer` role.
 *
 * The audit log is the record of who did what to borrower and loan data, and it
 * quotes the values that changed — so reading it is a way to read the data it
 * describes, including changes a reader would not otherwise be shown. `viewer`
 * is the lowest-privilege system role, handed out precisely when someone should
 * see operational screens and nothing sensitive. Audit access belongs with
 * admin and the manager-level roles that already hold it, and they keep it:
 * this touches the `viewer` grant and nothing else.
 *
 * This is hardening, not incident response. No account on any deployment
 * currently holds `viewer`, so nothing is being taken away from anyone today —
 * the point is that the next person granted the role does not silently inherit
 * the audit trail.
 *
 * Listed here AND removed from RoleAndPermissionSeeder on purpose, per the
 * convention that seeder states: the seeder is what a fresh database and the
 * whole test suite build from, and THIS is what changes the five deployments,
 * which are already migrated and will never re-run a seeder. The seeder edit
 * alone would have been a no-op everywhere it matters — a failure this project
 * has already had once, when code shipped and the fleet stayed as it was for
 * weeks.
 */
return new class extends Migration
{
    private const ROLE = 'viewer';

    private const PERMISSION = 'audit_logs:view';

    private const GUARD = 'web';

    public function up(): void
    {
        $roleId = $this->roleId();
        $permissionId = $this->permissionId();

        if (! $roleId || ! $permissionId) {
            return;
        }

        DB::table('role_has_permissions')
            ->where('role_id', $roleId)
            ->where('permission_id', $permissionId)
            ->delete();

        // The role's own description advertised the access being removed.
        // Updated only if it still reads exactly as seeded, so an operator who
        // has reworded it in the roles UI keeps their wording.
        DB::table('roles')
            ->where('id', $roleId)
            ->where('description', 'Read-only access to operational data and audit logs.')
            ->update(['description' => 'Read-only access to operational data.']);

        $this->flushPermissionCache();
    }

    public function down(): void
    {
        $roleId = $this->roleId();
        $permissionId = $this->permissionId();

        if (! $roleId || ! $permissionId) {
            return;
        }

        $alreadyGranted = DB::table('role_has_permissions')
            ->where('role_id', $roleId)
            ->where('permission_id', $permissionId)
            ->exists();

        if (! $alreadyGranted) {
            DB::table('role_has_permissions')->insert([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }

        DB::table('roles')
            ->where('id', $roleId)
            ->where('description', 'Read-only access to operational data.')
            ->update(['description' => 'Read-only access to operational data and audit logs.']);

        $this->flushPermissionCache();
    }

    private function roleId(): ?int
    {
        return DB::table('roles')
            ->where('name', self::ROLE)
            ->where('guard_name', self::GUARD)
            ->value('id');
    }

    private function permissionId(): ?int
    {
        return DB::table('permissions')
            ->where('name', self::PERMISSION)
            ->where('guard_name', self::GUARD)
            ->value('id');
    }

    private function flushPermissionCache(): void
    {
        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
