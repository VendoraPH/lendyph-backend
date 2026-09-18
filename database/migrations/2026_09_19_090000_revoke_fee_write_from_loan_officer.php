<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Take `fees:create`, `fees:update` and `fees:delete` away from `loan_officer`.
 *
 * Fee rules decide what every borrower is charged. A loan officer creating or
 * editing one changes the price of loans across the whole organization, not
 * just the application in front of them — that is an administrative decision,
 * and it belongs with `admin`, which keeps all four permissions.
 *
 * `fees:view` is deliberately RETAINED. Officers quote fees to borrowers while
 * assembling an application, so they still need to read the fee schedule; what
 * they lose is the ability to rewrite it. The other roles that hold `fees:view`
 * — cashier, collector, viewer, general_bookkeeper — are untouched, and none of
 * them ever held the write permissions.
 *
 * Listed here AND removed from RoleAndPermissionSeeder on purpose, per the
 * convention 2026_09_18_200001 states: the seeder is what a fresh database and
 * the whole test suite build from, and THIS is what changes the five
 * deployments, which are already migrated and will never re-run a seeder. The
 * seeder edit alone would have been a no-op everywhere it matters — a failure
 * this project has already had once, when code shipped and the fleet stayed as
 * it was for weeks.
 *
 * Unlike that migration there is no role description to correct:
 * `loan_officer` is described as "Creates and processes loan applications;
 * manages borrowers and share capital." and never advertised fee management.
 */
return new class extends Migration
{
    private const ROLE = 'loan_officer';

    private const PERMISSIONS = [
        'fees:create',
        'fees:update',
        'fees:delete',
    ];

    private const GUARD = 'web';

    public function up(): void
    {
        $roleId = $this->roleId();

        if (! $roleId) {
            return;
        }

        $permissionIds = $this->permissionIds();

        if (empty($permissionIds)) {
            return;
        }

        DB::table('role_has_permissions')
            ->where('role_id', $roleId)
            ->whereIn('permission_id', $permissionIds)
            ->delete();

        $this->flushPermissionCache();
    }

    public function down(): void
    {
        $roleId = $this->roleId();

        if (! $roleId) {
            return;
        }

        $permissionIds = $this->permissionIds();

        if (empty($permissionIds)) {
            return;
        }

        // Re-grant only what is actually missing, so a partial rollback — or a
        // second `migrate:rollback` — cannot violate the pivot's primary key.
        $alreadyGranted = DB::table('role_has_permissions')
            ->where('role_id', $roleId)
            ->whereIn('permission_id', $permissionIds)
            ->pluck('permission_id')
            ->all();

        $rows = collect($permissionIds)
            ->reject(fn (int $id) => in_array($id, $alreadyGranted, true))
            ->map(fn (int $id) => ['role_id' => $roleId, 'permission_id' => $id])
            ->all();

        if (! empty($rows)) {
            DB::table('role_has_permissions')->insert($rows);
        }

        $this->flushPermissionCache();
    }

    private function roleId(): ?int
    {
        return DB::table('roles')
            ->where('name', self::ROLE)
            ->where('guard_name', self::GUARD)
            ->value('id');
    }

    /**
     * @return list<int>
     */
    private function permissionIds(): array
    {
        return DB::table('permissions')
            ->whereIn('name', self::PERMISSIONS)
            ->where('guard_name', self::GUARD)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function flushPermissionCache(): void
    {
        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
