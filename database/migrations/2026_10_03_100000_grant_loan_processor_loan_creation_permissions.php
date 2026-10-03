<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Let `loan_processor` create loan applications.
 *
 * The role prepares applications and submits them into the approval chain,
 * but until now it held only `loans:view` and `loans:update`
 * (2026_09_16_110001_add_approval_chain_roles), so it could not create the
 * loan it was meant to prepare, nor read the borrower, fees, collaterals and
 * share capital the new-loan form shows. `collaterals:update` lets it attach the
 * member's collateral to that loan (POST /loans/{loan}/collaterals also checks
 * `loans:update`, which it already holds). Granted by the owner's decisions on
 * 2026-10-03.
 *
 * Listed here AND in RoleAndPermissionSeeder, per the convention stated there:
 * this migration grants them on staging and production, which are already
 * migrated and never re-run a seeder; the seeder is what a fresh database and
 * the test suite build from, where the role does not exist yet when this runs.
 *
 * GRANTS ONLY. It adds missing role_has_permissions rows for loan_processor
 * and nothing else: no permission is created, an existing grant is left as it
 * is, no other role is touched, and a box without the role is skipped.
 */
return new class extends Migration
{
    private const ROLE = 'loan_processor';

    private const PERMISSIONS = [
        'loans:create',
        'borrowers:view',
        'fees:view',
        'collaterals:view',
        'collaterals:update',
        'share_capital:view',
    ];

    public function up(): void
    {
        $roleId = $this->roleId();

        if ($roleId) {
            foreach ($this->permissionIds() as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }

        $this->forgetCachedPermissions();
    }

    /**
     * Takes the six back from loan_processor only, leaving its chain
     * permissions. There is no record of which grants up() added, so a rollback
     * also removes any of the six an admin had given the role by hand.
     */
    public function down(): void
    {
        $roleId = $this->roleId();

        if ($roleId) {
            DB::table('role_has_permissions')
                ->where('role_id', $roleId)
                ->whereIn('permission_id', $this->permissionIds())
                ->delete();
        }

        $this->forgetCachedPermissions();
    }

    private function roleId(): ?int
    {
        $id = DB::table('roles')
            ->where('name', self::ROLE)
            ->where('guard_name', 'web')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @return list<int>
     */
    private function permissionIds(): array
    {
        return DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', self::PERMISSIONS)
            ->pluck('id')
            ->all();
    }

    private function forgetCachedPermissions(): void
    {
        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
