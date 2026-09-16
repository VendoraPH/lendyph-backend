<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create the nine approval-chain roles that the default chains already name.
 *
 * ApprovalWorkflowSetting::DEFAULT_POLICY_EXCEPTION_STEPS routes ten steps
 * through `loan_processor`, `manager` and `bod1`..`bod7`, and NONE of those
 * roles existed — the seeder creates only super_admin, admin, loan_officer,
 * cashier, collector, viewer and general_bookkeeper. So nine of the ten
 * policy-exception steps resolved to a role no user could hold, and once the
 * chain is enforced server-side (loan_approval_steps) they would be
 * un-actionable by anyone except admin/super_admin. The normal chain has the
 * same hole at `loan_processor` and `manager`.
 *
 * Each gets `loans:view` and nothing else: a chain role's job is to look at the
 * loan in front of it and sign, and its authority to sign comes from holding
 * the role named on the step, not from a permission. Granting `loans:approve`
 * here would hand every BOD member the single-shot
 * PATCH /loans/{id}/approve and let them skip the chain entirely.
 *
 * Listed here AND in RoleAndPermissionSeeder on purpose, per the convention
 * stated in that seeder: this migration is what creates them on staging and
 * production, which are already migrated and never re-run a seeder; the seeder
 * is what a fresh database and the whole test suite build from.
 */
return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const ROLES = [
        'loan_processor' => 'Prepares loan applications and submits them into the approval chain; receives loans sent back for revision.',
        'manager' => 'Reviews submitted loans before they reach the Board of Directors.',
        'bod1' => 'Board of Directors member 1 (BOD Chairwoman in the normal chain) — signs off on loans in the approval chain.',
        'bod2' => 'Board of Directors member 2 — signs off on loans in the policy-exception approval chain.',
        'bod3' => 'Board of Directors member 3 — signs off on loans in the policy-exception approval chain.',
        'bod4' => 'Board of Directors member 4 — signs off on loans in the policy-exception approval chain.',
        'bod5' => 'Board of Directors member 5 — signs off on loans in the policy-exception approval chain.',
        'bod6' => 'Board of Directors member 6 — signs off on loans in the policy-exception approval chain.',
        'bod7' => 'Board of Directors member 7 — signs off on loans in the policy-exception approval chain.',
    ];

    /**
     * @var list<string>
     */
    private const GRANTED_PERMISSIONS = [
        'loans:view',
    ];

    /**
     * `loan_processor` alone also needs `loans:update`, because that is what
     * LoanController@submit checks — with only `loans:view` the role that the
     * default chain names as its FIRST step cannot start a chain at all, and
     * only the resubmit-after-send-back path works for it.
     *
     * Deliberately not `loans:approve`, and deliberately not extended to the
     * BOD roles: a chain role's authority to sign comes from holding the role
     * named on the step, not from a permission. `loans:approve` would hand out
     * the single-shot PATCH /loans/{id}/approve — which is now also refused
     * mid-chain by LoanService::guardApprovalChainIsClear(), but the narrower
     * grant is still the right shape.
     *
     * @var array<string, list<string>>
     */
    private const EXTRA_PERMISSIONS = [
        'loan_processor' => ['loans:update'],
    ];

    public function up(): void
    {
        $guard = 'web';
        $now = now();

        // INSERT-only, never updateOrInsert. These names are generic —
        // `manager` and `loan_processor` especially — and an admin on any of
        // the ten deployments may already have built one by hand in the roles
        // UI. Overwriting it would force `is_system = true` (making it
        // undeletable and unrenamable), silently flip a deactivated role back
        // to active, and rewrite its description and created_at. An existing
        // role is left exactly as it is; only the `loans:view` grant below is
        // applied to it, which is what the chain actually needs.
        foreach (self::ROLES as $name => $description) {
            $exists = DB::table('roles')
                ->where('name', $name)
                ->where('guard_name', $guard)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('roles')->insert([
                'name' => $name,
                'guard_name' => $guard,
                'description' => $description,
                'is_system' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        $wanted = self::GRANTED_PERMISSIONS;

        foreach (self::EXTRA_PERMISSIONS as $extra) {
            $wanted = array_merge($wanted, $extra);
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', array_unique($wanted))
            ->where('guard_name', $guard)
            ->pluck('id', 'name');

        $roleIds = DB::table('roles')
            ->whereIn('name', array_keys(self::ROLES))
            ->where('guard_name', $guard)
            ->pluck('id', 'name');

        foreach ($roleIds as $roleName => $roleId) {
            $grants = array_merge(
                self::GRANTED_PERMISSIONS,
                self::EXTRA_PERMISSIONS[$roleName] ?? [],
            );

            foreach ($grants as $permName) {
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
        $guard = 'web';

        $roleIds = DB::table('roles')
            ->whereIn('name', array_keys(self::ROLES))
            ->where('guard_name', $guard)
            ->pluck('id');

        // A role somebody is actually assigned to is not this migration's to
        // delete: up() skips names that already existed, so one of these may
        // predate the chain entirely, and dropping it would revoke a live
        // user's access on a rollback. Such a role keeps everything except the
        // `loans:view` grant added above.
        $assigned = DB::table('model_has_roles')
            ->whereIn('role_id', $roleIds)
            ->distinct()
            ->pluck('role_id');

        $removable = $roleIds->diff($assigned);

        $permissionIds = DB::table('permissions')
            ->whereIn('name', self::GRANTED_PERMISSIONS)
            ->where('guard_name', $guard)
            ->pluck('id');

        DB::table('role_has_permissions')
            ->whereIn('role_id', $roleIds)
            ->whereIn('permission_id', $permissionIds)
            ->delete();

        DB::table('role_has_permissions')->whereIn('role_id', $removable)->delete();
        DB::table('roles')->whereIn('id', $removable)->delete();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
